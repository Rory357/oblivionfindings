#!/usr/bin/env python3
"""Freeze RUN-133's six pending accounting-integration route actions.

The reviewed RUN-131/RUN-132 boundary leaves 399 RUN-090 queue rows pending.
This producer selects queue indices 71 through 76 only. The already-reviewed
index action, the backend-only sync sibling, both existing pages, and the next
invoice queue row are context boundaries and cannot contribute inherited
ownership. No semantic outcome, ownership, correctness, runtime, browser,
test, benchmark, Pass, final-finding, or completion credit is awarded here.
"""

from __future__ import annotations

import csv
import hashlib
import importlib.util
import json
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
BASE_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py"
)
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

CHECKPOINT_COMMIT = "6a92f583b2d675411033af632a6b4fbd4cf48c17"
CHECKPOINT_TREE = "fde0dfb77b040ad09dc32f354c53c02ae9a960df"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
FEATURE_ID = "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION"

EXPECTED_QUEUE_IDS = [
    "RUN090-ROUTE-0072",
    "RUN090-ROUTE-0073",
    "RUN090-ROUTE-0074",
    "RUN090-ROUTE-0075",
    "RUN090-ROUTE-0076",
    "RUN090-ROUTE-0077",
]
EXPECTED_ROUTE_IDS = [
    "RUN077-ROUTE-0593",
    "RUN077-ROUTE-0594",
    "RUN077-ROUTE-0596",
    "RUN077-ROUTE-0597",
    "RUN077-ROUTE-0598",
    "RUN077-ROUTE-0599",
]
EXPECTED_QUEUE_INDICES = [71, 72, 73, 74, 75, 76]
EXPECTED_METHODS = [
    "store",
    "update",
    "testConnection",
    "destroy",
    "mapping",
    "updateMapping",
]
PARTITION_BY_ROUTE_ID = {
    "RUN077-ROUTE-0593": "A",
    "RUN077-ROUTE-0594": "A",
    "RUN077-ROUTE-0596": "B",
    "RUN077-ROUTE-0597": "B",
    "RUN077-ROUTE-0598": "C",
    "RUN077-ROUTE-0599": "C",
}
PRIOR_INDEX_ROUTE_ID = "RUN077-ROUTE-0592"
EXCLUDED_SYNC_ROUTE_ID = "RUN077-ROUTE-0595"
NEXT_QUEUE_ID = "RUN090-ROUTE-0078"
NEXT_ROUTE_ID = "RUN077-ROUTE-0634"

spec = importlib.util.spec_from_file_location("run129_base", BASE_GENERATOR)
assert spec and spec.loader
BASE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(BASE)

sha256_file = BASE.sha256_file
canonical_json_sha256 = BASE.canonical_json_sha256
canonical_list_sha256 = BASE.canonical_list_sha256
load_json = BASE.load_json
git = BASE.git
index_unique = BASE.index_unique
semantic_slice = BASE.semantic_slice
transitive_local_helper_slices = BASE.transitive_local_helper_slices
request_contracts_for_slice = BASE.request_contracts_for_slice
feature_projection = BASE.feature_projection
cohort_route_ids = BASE.cohort_route_ids

INPUT_PATHS = {
    "base_generator": BASE_GENERATOR,
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "task_contract": AUDIT_DIR / "task-scripts/cap-fin-accounting-integration-configuration.md",
    "manifest": AUDIT_DIR / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR / "evidence/source/current-route-page-classification-wave-07.json",
    "candidate_manifest": AUDIT_DIR / "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json",
    "candidate_review": AUDIT_DIR / "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json",
    "ownership_ledger": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "direct_queue_generator": AUDIT_DIR / "generators/build-direct-exact-route-page-review-queue-wave-11.py",
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run092_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "run092_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "run097_cohort": AUDIT_DIR / "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json",
    "run098_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "run101_cohort": AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json",
    "run102_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run106_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run110_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "run113_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "run114_overlay": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "run118_overlay": AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "run121_cohort": AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "run122_overlay": AUDIT_DIR / "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
    "run126_overlay": AUDIT_DIR / "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
    "run129_cohort": AUDIT_DIR / "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json",
    "run130_overlay": AUDIT_DIR / "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
    "run130_review": AUDIT_DIR / "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
    "run131_reporting": AUDIT_DIR / "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json",
    "run132_dashboard": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "2e23ca7736f0e21460f130a6fafc89a68f228b6f8a52137a2209795d500b0982",
    "matrix": BASE.EXPECTED_INPUT_SHA256["matrix"],
    "task_contract": "7bebcd54556e346d8d543acf8e2458ac5578e24b51879e5fd8f1ad65abfa1422",
    "manifest": BASE.EXPECTED_INPUT_SHA256["manifest"],
    "classification": BASE.EXPECTED_INPUT_SHA256["classification"],
    "candidate_manifest": BASE.EXPECTED_INPUT_SHA256["candidate_manifest"],
    "candidate_review": BASE.EXPECTED_INPUT_SHA256["candidate_review"],
    "ownership_ledger": BASE.EXPECTED_INPUT_SHA256["ownership_ledger"],
    "direct_queue_generator": "73b12d328cfee86631670b0b6b6a9bb6e7cc4ee45380af1136d361584f6d241d",
    "direct_queue": BASE.EXPECTED_INPUT_SHA256["direct_queue"],
    "run091_cohort": BASE.EXPECTED_INPUT_SHA256["run091_cohort"],
    "run092_overlay": BASE.EXPECTED_INPUT_SHA256["run092_overlay"],
    "run092_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "run097_cohort": BASE.EXPECTED_INPUT_SHA256["run097_cohort"],
    "run098_overlay": BASE.EXPECTED_INPUT_SHA256["run098_overlay"],
    "run101_cohort": BASE.EXPECTED_INPUT_SHA256["run101_cohort"],
    "run102_overlay": BASE.EXPECTED_INPUT_SHA256["run102_overlay"],
    "run106_overlay": BASE.EXPECTED_INPUT_SHA256["run106_overlay"],
    "run110_overlay": BASE.EXPECTED_INPUT_SHA256["run110_overlay"],
    "run113_cohort": BASE.EXPECTED_INPUT_SHA256["run113_cohort"],
    "run114_overlay": BASE.EXPECTED_INPUT_SHA256["run114_overlay"],
    "run118_overlay": BASE.EXPECTED_INPUT_SHA256["run118_overlay"],
    "run121_cohort": BASE.EXPECTED_INPUT_SHA256["run121_cohort"],
    "run122_overlay": BASE.EXPECTED_INPUT_SHA256["run122_overlay"],
    "run126_overlay": BASE.EXPECTED_INPUT_SHA256["run126_overlay"],
    "run129_cohort": "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c",
    "run130_overlay": "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a",
    "run130_review": "4f7e5d74ce3711ce5ff00ac2a499ddde125115b1537a0de2e17375792f3d8590",
    "run131_reporting": "191d428161b0f96758bf4ca32d968d87cd9efb1e0a4e9fdd26741f8952063099",
    "run132_dashboard": "c6b8991bd63628bc9dc34bd458067cd89cb612cbb8096f2c9f5fa7792d5c3014",
}

OVERLAY_NAMES = (
    "run092_overlay",
    "run098_overlay",
    "run102_overlay",
    "run106_overlay",
    "run110_overlay",
    "run114_overlay",
    "run118_overlay",
    "run122_overlay",
    "run126_overlay",
    "run130_overlay",
)
COHORT_NAMES = (
    "run091_cohort",
    "run097_cohort",
    "run101_cohort",
    "run113_cohort",
    "run121_cohort",
    "run129_cohort",
)

SOURCE_FILE_PURPOSES = {
    "routes/web.php": {
        "review_loci": ["routes/web.php:369"],
        "purpose": "committed web-route loader establishing the finance route file context",
    },
    "routes/finance.php": {
        "review_loci": ["routes/finance.php:62", "routes/finance.php:602-612"],
        "purpose": "finance prefix, finance.admin middleware, exact selected routes, and excluded sync sibling",
    },
    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php": {
        "review_loci": [
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:1-242",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:72-121",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143-241",
        ],
        "purpose": "complete controller, six selected actions, excluded sync action, and organization helper",
    },
    "app/Domain/Finance/Models/FinAccountingIntegration.php": {
        "review_loci": ["app/Domain/Finance/Models/FinAccountingIntegration.php:1-112"],
        "purpose": "storage fields, encryption casts, scopes, soft deletion, mappings, and token helpers",
    },
    "app/Domain/Finance/Models/FinAccount.php": {
        "review_loci": ["app/Domain/Finance/Models/FinAccount.php:13-98"],
        "purpose": "mapping-page account projection and legacy organization filter",
    },
    "app/Domain/Finance/Services/GlSyncService.php": {
        "review_loci": ["app/Domain/Finance/Services/GlSyncService.php:17-260"],
        "purpose": "test-provider selection and excluded manual-sync service context",
    },
    "app/Domain/Finance/Contracts/AccountingSyncProviderInterface.php": {
        "review_loci": ["app/Domain/Finance/Contracts/AccountingSyncProviderInterface.php:1-79"],
        "purpose": "provider connection-test and synchronization contract",
    },
    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php": {
        "review_loci": [
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:300-364",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:520-635",
        ],
        "purpose": "Xero connection test, OAuth refresh, API call, tenant identifier, and mapping persistence",
    },
    "app/Domain/Finance/Services/AccountingSyncProviders/MyobSyncProvider.php": {
        "review_loci": ["app/Domain/Finance/Services/AccountingSyncProviders/MyobSyncProvider.php:1-98"],
        "purpose": "MYOB unsupported semantics and deterministic false connection test",
    },
    "app/Domain/Finance/Jobs/SyncAccountingIntegrationJob.php": {
        "review_loci": ["app/Domain/Finance/Jobs/SyncAccountingIntegrationJob.php:1-114"],
        "purpose": "excluded sync sibling execution and record lookup context only",
    },
    "app/Domain/Finance/Models/FinGlSyncLog.php": {
        "review_loci": ["app/Domain/Finance/Models/FinGlSyncLog.php:1-56"],
        "purpose": "excluded sync sibling log context only",
    },
    "resources/js/pages/finance/Integrations/Index.tsx": {
        "review_loci": ["resources/js/pages/finance/Integrations/Index.tsx:1-570"],
        "purpose": "existing page owner and literal callers for store, test, destroy, mapping, and excluded sync",
    },
    "resources/js/pages/finance/Integrations/Mapping.tsx": {
        "review_loci": ["resources/js/pages/finance/Integrations/Mapping.tsx:63-122"],
        "purpose": "existing cross-feature page owner and literal updateMapping caller",
    },
    "resources/js/components/finance/settings-hub.tsx": {
        "review_loci": ["resources/js/components/finance/settings-hub.tsx:1-50"],
        "purpose": "navigation context only, not route or page ownership",
    },
    "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php": {
        "review_loci": ["database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:1-39"],
        "purpose": "provider uniqueness, provider account identifier, encrypted-field storage, mappings, and soft delete schema",
    },
    "database/migrations/2026_03_28_004000_add_external_ids_to_fin_tables.php": {
        "review_loci": ["database/migrations/2026_03_28_004000_add_external_ids_to_fin_tables.php:1-50"],
        "purpose": "provider external account identifier columns consumed by mapping and Xero posting",
    },
    "database/migrations/2026_04_24_000100_add_organization_scope_to_users_and_clients.php": {
        "review_loci": ["database/migrations/2026_04_24_000100_add_organization_scope_to_users_and_clients.php:12-38"],
        "purpose": "nullable and defaulted user organization context consumed by the selected controller",
    },
    "bootstrap/app.php": {
        "review_loci": ["bootstrap/app.php:1-96"],
        "purpose": "permission middleware registration context",
    },
    "app/Http/Middleware/EnsurePermission.php": {
        "review_loci": ["app/Http/Middleware/EnsurePermission.php:1-29"],
        "purpose": "literal finance.admin permission enforcement path",
    },
    "app/Http/Middleware/EnsureAccountStillApproved.php": {
        "review_loci": ["app/Http/Middleware/EnsureAccountStillApproved.php:1-37"],
        "purpose": "approved-account middleware context without selected-object ownership semantics",
    },
    "app/Http/Middleware/HandleInertiaRequests.php": {
        "review_loci": [
            "app/Http/Middleware/HandleInertiaRequests.php:1-180",
            "app/Http/Middleware/HandleInertiaRequests.php:1018-1022",
        ],
        "purpose": "shared Inertia permission and actor context",
    },
    "app/Models/User.php": {
        "review_loci": ["app/Models/User.php:359-407"],
        "purpose": "canDo permission semantics and actor context",
    },
    "app/Providers/AuthServiceProvider.php": {
        "review_loci": ["app/Providers/AuthServiceProvider.php:137-216"],
        "purpose": "policy registration context confirming no explicit integration policy mapping",
    },
    "database/seeders/RbacSeeder.php": {
        "review_loci": [
            "database/seeders/RbacSeeder.php:524-545",
            "database/seeders/RbacSeeder.php:779-790",
        ],
        "purpose": "role and permission assignment context for finance.admin",
    },
    "database/seeders/FinancePermissionsSeeder.php": {
        "review_loci": ["database/seeders/FinancePermissionsSeeder.php:1-129"],
        "purpose": "finance.admin capability declaration and role assignment context",
    },
    "app/Models/Concerns/AuditableChanges.php": {
        "review_loci": ["app/Models/Concerns/AuditableChanges.php:1-102"],
        "purpose": "create, update, and delete audit snapshot semantics for the integration model",
    },
    "app/Support/SafeOperationalData.php": {
        "review_loci": ["app/Support/SafeOperationalData.php:24-69", "app/Support/SafeOperationalData.php:201-212"],
        "purpose": "request-context and audit-field protection dependency",
    },
    "app/Services/AuditLogger.php": {
        "review_loci": ["app/Services/AuditLogger.php:1-75"],
        "purpose": "audit-event persistence and actor context dependency",
    },
    "docs/architecture/single-tenant-application.md": {
        "review_loci": ["docs/architecture/single-tenant-application.md:1-21"],
        "purpose": "canonical one-organisation multi-Site architecture and legacy organization-field boundary",
    },
    "config/services.php": {
        "review_loci": ["config/services.php:1-129"],
        "purpose": "Xero client credential configuration context",
    },
    ".env.example": {
        "review_loci": [".env.example:1-220"],
        "purpose": "documented external provider configuration names without runtime-secret evidence",
    },
    "tests/Browser/Finance/FinanceIntegrationMappingInteractionTest.php": {
        "review_loci": ["tests/Browser/Finance/FinanceIntegrationMappingInteractionTest.php:1-72"],
        "purpose": "unexecuted browser mapping-update context only",
    },
    "tests/Browser/Finance/FinanceMiscTest.php": {
        "review_loci": ["tests/Browser/Finance/FinanceMiscTest.php:126-134"],
        "purpose": "unexecuted index load-only context",
    },
    "tests/Feature/Finance/XeroAccountingSyncProviderTest.php": {
        "review_loci": ["tests/Feature/Finance/XeroAccountingSyncProviderTest.php:1-285"],
        "purpose": "unexecuted provider-focused test context, not selected route-action proof",
    },
    "tests/Feature/Finance/SettingsHubTest.php": {
        "review_loci": ["tests/Feature/Finance/SettingsHubTest.php:1-25"],
        "purpose": "unexecuted settings redirect context only",
    },
}

DEPENDENCY_METHOD_SPECS = [
    ("app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php", "authorizeOrganization"),
    ("app/Domain/Finance/Models/FinAccountingIntegration.php", "scopeForOrganization"),
    ("app/Domain/Finance/Models/FinAccountingIntegration.php", "isTokenExpired"),
    ("app/Domain/Finance/Models/FinAccount.php", "scopeForOrganization"),
    ("app/Domain/Finance/Services/GlSyncService.php", "getProvider"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "testConnection"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "refreshToken"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "apiRequest"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "sendApiRequest"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "ensureCanCallApi"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "tenantId"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php", "rememberAccountMapping"),
    ("app/Domain/Finance/Services/AccountingSyncProviders/MyobSyncProvider.php", "testConnection"),
]


def exact_source_line(relative: str, needle: str) -> dict[str, Any]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    matches = [(number, line) for number, line in enumerate(lines, 1) if needle in line]
    assert len(matches) == 1, (relative, needle, matches)
    number, line = matches[0]
    return {
        "source_file": relative,
        "source_file_sha256": sha256_file(REPO / relative),
        "source_file_blob_id": git("rev-parse", f"HEAD:{relative}"),
        "source_anchor": f"{relative}:{number}",
        "source_line": line.strip(),
        "source_line_sha256": hashlib.sha256(line.encode("utf-8")).hexdigest(),
    }


def assert_workspace_and_inputs() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database") == ""
    allowed = {
        f"?? {Path(__file__).relative_to(REPO).as_posix()}",
        f"?? {OUTPUT_PATH.relative_to(REPO).as_posix()}",
    }
    status = {line for line in git("status", "--porcelain").splitlines() if line}
    assert status <= allowed, status
    assert PROMPT_PATH.is_file()
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, target in INPUT_PATHS.items():
        assert target.is_file(), target
        assert sha256_file(target) == EXPECTED_INPUT_SHA256[name], name
    for relative in SOURCE_FILE_PURPOSES:
        target = REPO / relative
        assert target.is_file(), target
        assert git("rev-parse", f"HEAD:{relative}") == git(
            "rev-parse", f"{APPLICATION_COMMIT}:{relative}"
        ), relative


def source_review_packet() -> dict[str, Any]:
    required_files = []
    for relative, spec_data in SOURCE_FILE_PURPOSES.items():
        required_files.append(
            {
                "path": relative,
                "sha256": sha256_file(REPO / relative),
                "blob_id": git("rev-parse", f"HEAD:{relative}"),
                "application_commit_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
                "review_loci": spec_data["review_loci"],
                "purpose": spec_data["purpose"],
            }
        )
    dependency_slices = [semantic_slice(path, method) for path, method in DEPENDENCY_METHOD_SPECS]
    packet = {
        "source_tree_pinning_basis": {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "head_app_tree": APP_TREE,
            "head_routes_tree": ROUTES_TREE,
            "head_resources_js_tree": RESOURCES_JS_TREE,
            "head_tests_tree": TESTS_TREE,
            "every_required_file_matches_application_commit_blob": True,
        },
        "required_source_files": required_files,
        "required_source_file_count": len(required_files),
        "required_source_file_identity_sha256": canonical_list_sha256(
            [f"{row['path']}|{row['sha256']}|{row['blob_id']}" for row in required_files]
        ),
        "material_dependency_method_slices": dependency_slices,
        "material_dependency_method_slice_count": len(dependency_slices),
        "source_review_complete": False,
        "source_packet_completeness_claimed": False,
        "material_dependency_semantics_complete": False,
        "excluded_sync_semantics_complete": False,
        "unexecuted_test_context_is_runtime_evidence": False,
        "unresolved_semantic_gap_inventory": [
            {"gap": "exact_action_permission_and_policy", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "canonical_record_scope_and_direct_object_concealment", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "single_organisation_multi_site_scope", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "provider_identifier_and_secret_handling", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "settings_and_mapping_validation", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "connection_test_error_concealment", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "soft_delete_reconnect_and_external_revocation", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "cross_feature_page_context", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
            {"gap": "backend_only_sync_sibling", "status": "EXCLUDED_CONTEXT_NOT_REVIEWED"},
            {"gap": "selected_actions_executable_tests", "status": "REQUIRES_FRESH_SEMANTIC_REVIEW"},
        ],
        "review_rule": (
            "Review each selected controller action completely and follow every frozen material dependency. "
            "Keep the backend-only sync sibling outside this cohort. Unresolved authorization, Site, direct-object, "
            "privacy, provider, secret, mapping, lifecycle, concurrency, or test semantics require EVIDENCE_GAP."
        ),
    }
    packet["source_review_packet_sha256"] = canonical_json_sha256(packet)
    return packet


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidates = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    queue = load_json(INPUT_PATHS["direct_queue"])
    cohorts = {name: load_json(INPUT_PATHS[name]) for name in COHORT_NAMES}
    overlays = {name: load_json(INPUT_PATHS[name]) for name in OVERLAY_NAMES}
    run092_review = load_json(INPUT_PATHS["run092_review"])
    run130_review = load_json(INPUT_PATHS["run130_review"])
    run131 = load_json(INPUT_PATHS["run131_reporting"])
    run132 = load_json(INPUT_PATHS["run132_dashboard"])

    assert candidate_review["verdict"]["decision"] == "GO"
    assert run092_review["decision"]["verdict"] == "GO"
    assert run130_review["decision"]["verdict"] == "GO"
    assert run132["verification"]["state"] == "GO"
    assert run132["pins"]["reporting_receipt_sha256"] == EXPECTED_INPUT_SHA256["run131_reporting"]
    assert run132["audit_completion_test_met"] is False

    expected_baseline = {
        "source_owner_records": 654,
        "route_owner_records": 297,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 62,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 48,
        "static_controller_action_bridges": 85,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.645457",
        "bounded_static_source_residual_records": 3275,
        "residual_explicit_unmapped_routes": 2904,
        "semantic_shared_routes": 12,
        "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345,
        "semantic_shared_page_roots": 9,
        "reviewed_alias_page_roots": 0,
        "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 108,
        "owner_queue_surface_rows": 86,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 399,
        "queue_surfaces_without_ownership": 421,
    }
    for key, value in expected_baseline.items():
        assert run131["counts"][key] == value, key

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_by_id = index_unique(route_rows, "route_record_id")
    decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    candidate_by_id = index_unique(
        candidates["route_static_candidate_census"]["records"], "route_record_id"
    )

    current_owner_rows: list[dict[str, Any]] = list(ownership["records"])
    owner_origin: dict[str, str] = {
        row["source_record_id"]: "ownership_ledger" for row in ownership["records"]
    }
    bridge_rows: list[dict[str, Any]] = []
    bridge_origin: dict[str, str] = {}
    for name in OVERLAY_NAMES:
        overlay = overlays[name]
        for row in overlay["overlay_source_records"]:
            assert row["source_record_id"] not in owner_origin
            current_owner_rows.append(row)
            owner_origin[row["source_record_id"]] = name
        for field in ("static_controller_action_bridges", "new_static_controller_action_bridges"):
            for row in overlay.get(field, []):
                assert row["bridge_id"] not in bridge_origin
                bridge_rows.append(row)
                bridge_origin[row["bridge_id"]] = name

    current_owner_by_id = index_unique(current_owner_rows, "source_record_id")
    current_owner_ids = set(current_owner_by_id)
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    assert len(current_owner_rows) == len(current_owner_ids) == 654
    assert Counter(row["surface"] for row in current_owner_rows) == {
        "ROUTE_SOURCE_RECORD": 297,
        "PAGE_ROOT_SOURCE_RECORD": 357,
    }
    assert len(current_owner_features) == 256
    assert FEATURE_ID in current_owner_features
    assert len(bridge_rows) == len(bridge_origin) == 85

    reviewed_route_ids: set[str] = set()
    for cohort in cohorts.values():
        reviewed_route_ids |= cohort_route_ids(cohort)
    assert len(reviewed_route_ids) == 106
    assert PRIOR_INDEX_ROUTE_ID in reviewed_route_ids
    assert PRIOR_INDEX_ROUTE_ID in current_owner_ids
    assert all(route_id not in reviewed_route_ids for route_id in EXPECTED_ROUTE_IDS)
    assert all(route_id not in current_owner_ids for route_id in EXPECTED_ROUTE_IDS)
    assert EXCLUDED_SYNC_ROUTE_ID not in reviewed_route_ids
    assert EXCLUDED_SYNC_ROUTE_ID not in current_owner_ids

    feature_queue = [
        (index, row)
        for index, row in enumerate(queue["records"])
        if row["candidate_feature_id"] == FEATURE_ID
    ]
    assert [row["source_record_id"] for _, row in feature_queue] == [
        PRIOR_INDEX_ROUTE_ID,
        *EXPECTED_ROUTE_IDS,
    ]
    assert [index for index, _ in feature_queue] == [70, *EXPECTED_QUEUE_INDICES]
    assert not any(row["source_record_id"] == EXCLUDED_SYNC_ROUTE_ID for row in queue["records"])

    selected = [
        (index, row) for index, row in feature_queue if row["queue_id"] in EXPECTED_QUEUE_IDS
    ]
    assert [index for index, _ in selected] == EXPECTED_QUEUE_INDICES
    assert [row["queue_id"] for _, row in selected] == EXPECTED_QUEUE_IDS
    assert [row["source_record_id"] for _, row in selected] == EXPECTED_ROUTE_IDS
    next_index, next_row = 77, queue["records"][77]
    assert next_row["queue_id"] == NEXT_QUEUE_ID
    assert next_row["source_record_id"] == NEXT_ROUTE_ID
    assert next_row["candidate_feature_id"] == "CAP-FIN-BILLING-INVOICE-LIFECYCLE"

    excluded_sync_route = route_by_id[EXCLUDED_SYNC_ROUTE_ID]
    excluded_sync_candidate = candidate_by_id[EXCLUDED_SYNC_ROUTE_ID]
    excluded_sync_decision = decision_by_id[EXCLUDED_SYNC_ROUTE_ID]
    assert excluded_sync_route["direct_name_literal"] == "integrations.sync"
    assert excluded_sync_candidate["relation_comparison"] == "BACKEND_ONLY"
    assert excluded_sync_candidate["name_relation"]["candidate_count"] == 0
    assert excluded_sync_candidate["backend_method_relation"]["candidate_feature_ids"] == [FEATURE_ID]
    assert excluded_sync_candidate["backend_method_relation"]["resolution"]["method"] == "sync"
    assert excluded_sync_decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"

    prior_owner_specs = [
        (PRIOR_INDEX_ROUTE_ID, "run092_overlay", "ALREADY_REVIEWED_INDEX_ROUTE"),
        ("PAGE-ROOT-679D3E7F4B5402CB", "run092_overlay", "EXISTING_INDEX_CALLER_PAGE"),
        ("PAGE-ROOT-BA2E4950746EAF10", "ownership_ledger", "EXISTING_MAPPING_RENDER_PAGE"),
    ]
    prior_owner_context: list[dict[str, Any]] = []
    for source_id, expected_origin, relation in prior_owner_specs:
        row = current_owner_by_id[source_id]
        assert owner_origin[source_id] == expected_origin
        prior_owner_context.append(
            {
                "relation": relation,
                "surface": row["surface"],
                "source_record_id": source_id,
                "source_record_key": row["source_record_key"],
                "feature_id": row["feature_id"],
                "owner_artifact": INPUT_PATHS[expected_origin].relative_to(AUDIT_DIR).as_posix(),
                "owner_row_id": row.get("mapping_id") or row.get("overlay_mapping_id"),
                "owner_row_sha256": row.get("ledger_row_sha256") or row.get("overlay_row_sha256"),
                "current_static_owner_credit_preserved": True,
                "ownership_inheritable_to_run133": False,
                "route_action_bridge_inheritable_to_run133": False,
                "correctness_or_downstream_credit_inheritable_to_run133": False,
            }
        )
    assert prior_owner_context[0]["feature_id"] == FEATURE_ID
    assert prior_owner_context[1]["feature_id"] == FEATURE_ID
    assert prior_owner_context[2]["feature_id"] == "CAP-FIN-XERO-ACCOUNTING-SYNC"

    prior_index_bridges = [
        row
        for row in bridge_rows
        if row["route_record_id"] == PRIOR_INDEX_ROUTE_ID
        and row["controller_file"]
        == "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php"
        and row["method"] == "index"
        and row["feature_id"] == FEATURE_ID
    ]
    assert len(prior_index_bridges) == 1
    prior_bridge = prior_index_bridges[0]
    assert prior_bridge["bridge_id"] == "RUN092-BRIDGE-03"
    assert bridge_origin[prior_bridge["bridge_id"]] == "run092_overlay"
    prior_bridge_context = {
        "bridge_id": prior_bridge["bridge_id"],
        "bridge_key": [
            prior_bridge["controller_file"],
            prior_bridge["method"],
            prior_bridge["feature_id"],
        ],
        "route_record_id": prior_bridge["route_record_id"],
        "page_record_id": prior_bridge.get("page_record_id"),
        "bridge_row_sha256": prior_bridge["bridge_row_sha256"],
        "owner_artifact": INPUT_PATHS["run092_overlay"].relative_to(AUDIT_DIR).as_posix(),
        "current_static_bridge_credit_preserved": True,
        "bridge_inheritable_to_run133": False,
        "ownership_or_correctness_inheritable_to_run133": False,
    }

    page_context_by_id = {
        row["source_record_id"]: row
        for row in prior_owner_context
        if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    caller_specs: dict[str, tuple[str, str, str] | None] = {
        "RUN077-ROUTE-0593": (
            "resources/js/pages/finance/Integrations/Index.tsx",
            "post('/finance/integrations', {",
            "PAGE-ROOT-679D3E7F4B5402CB",
        ),
        "RUN077-ROUTE-0594": None,
        "RUN077-ROUTE-0596": (
            "resources/js/pages/finance/Integrations/Index.tsx",
            "`/finance/integrations/${integration.id}/test`,",
            "PAGE-ROOT-679D3E7F4B5402CB",
        ),
        "RUN077-ROUTE-0597": (
            "resources/js/pages/finance/Integrations/Index.tsx",
            "router.delete(`/finance/integrations/${integration.id}`);",
            "PAGE-ROOT-679D3E7F4B5402CB",
        ),
        "RUN077-ROUTE-0598": (
            "resources/js/pages/finance/Integrations/Index.tsx",
            "`/finance/integrations/${integration.id}/mapping`,",
            "PAGE-ROOT-679D3E7F4B5402CB",
        ),
        "RUN077-ROUTE-0599": (
            "resources/js/pages/finance/Integrations/Mapping.tsx",
            "put(`/finance/integrations/${integration.id}/mapping`, {",
            "PAGE-ROOT-BA2E4950746EAF10",
        ),
    }
    frontend_contexts: dict[str, dict[str, Any]] = {}
    for route_id, caller_spec in caller_specs.items():
        if caller_spec is None:
            frontend_contexts[route_id] = {
                "literal_caller_in_frozen_integration_pages": None,
                "literal_caller_count": 0,
                "absence_is_dead_route_evidence": False,
                "page_ownership_inheritable": False,
            }
            continue
        page_file, needle, page_id = caller_spec
        page_owner = page_context_by_id[page_id]
        frontend_contexts[route_id] = {
            **exact_source_line(page_file, needle),
            "literal_caller_count": 1,
            "page_record_id": page_id,
            "current_page_feature_id": page_owner["feature_id"],
            "current_static_page_owner": True,
            "page_ownership_inheritable": False,
            "caller_presence_preselects_route_outcome": False,
        }

    packet = source_review_packet()
    existing_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"]) for row in bridge_rows
    }
    assert len(existing_bridge_keys) == len(bridge_rows) == 85

    records: list[dict[str, Any]] = []
    for sequence, (queue_index, queue_row) in enumerate(selected, 1):
        route_id = queue_row["source_record_id"]
        route_row = route_by_id[route_id]
        decision = decision_by_id[route_id]
        candidate = candidate_by_id[route_id]
        backend = candidate["backend_method_relation"]
        resolution = backend["resolution"]

        assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
        assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
        assert queue_row["secondary_lane"]["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert queue_row["secondary_lane"]["contradictory_candidate_present"] is False
        assert decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
        assert candidate["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert candidate["name_relation"]["candidate_feature_ids"] == [FEATURE_ID]
        assert backend["candidate_count"] == 1
        assert backend["candidate_feature_ids"] == [FEATURE_ID]
        assert resolution["status"] == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
        assert resolution["controller_file"] == (
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php"
        )
        assert resolution["method"] == EXPECTED_METHODS[sequence - 1]
        assert sha256_file(REPO / route_row["route_file"]) == route_row["route_file_sha256"]
        assert sha256_file(REPO / resolution["controller_file"]) == resolution["controller_file_sha256"]

        primary = semantic_slice(resolution["controller_file"], resolution["method"])
        assert primary["definition_line"] == resolution["definition_line"]
        helpers = transitive_local_helper_slices(
            resolution["controller_file"], resolution["method"], primary["review_slice"]["text"]
        )
        requests = request_contracts_for_slice(
            resolution["controller_file"], primary["review_slice"]["text"]
        )
        bridge_key = (resolution["controller_file"], resolution["method"], FEATURE_ID)
        assert bridge_key not in existing_bridge_keys

        inertia_callsites: list[dict[str, Any]] = []
        if route_id == "RUN077-ROUTE-0598":
            inertia_callsites.append(
                {
                    **exact_source_line(
                        resolution["controller_file"],
                        "return Inertia::render('finance/Integrations/Mapping', [",
                    ),
                    "render_name": "finance/Integrations/Mapping",
                    "existing_page_record_id": "PAGE-ROOT-BA2E4950746EAF10",
                    "existing_page_feature_id": "CAP-FIN-XERO-ACCOUNTING-SYNC",
                    "page_owner_inheritance_authorized": False,
                    "page_reassignment_authorized": False,
                }
            )

        action_key = f"{route_id}|{resolution['controller_file']}:{resolution['method']}|{FEATURE_ID}"
        record: dict[str, Any] = {
            "candidate_id": f"RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-{sequence:02d}",
            "action_key": action_key,
            "review_partition": PARTITION_BY_ROUTE_ID[route_id],
            "run090_original_partition": queue_row["review_partition"],
            "queue_index_zero_based": queue_index,
            "queue_id": queue_row["queue_id"],
            "queue_canonical_key": queue_row["canonical_key"],
            "candidate_feature_id": FEATURE_ID,
            "converged_identity": {
                "direct_identity": queue_row["direct_identity"],
                "relation_comparison": "BOTH_LANES_IDENTICAL",
                "name_candidate_count": candidate["name_relation"]["candidate_count"],
                "name_candidate_feature_ids": candidate["name_relation"]["candidate_feature_ids"],
                "backend_candidate_count": backend["candidate_count"],
                "backend_candidate_feature_ids": backend["candidate_feature_ids"],
                "matching_backend_anchors": backend["matching_matrix_anchors"],
                "two_identical_static_lanes_preselect_semantic_ownership": False,
                "candidate_only": True,
            },
            "route_source": {
                "route_record_id": route_id,
                "route_file": route_row["route_file"],
                "route_file_sha256": route_row["route_file_sha256"],
                "route_file_blob_id": route_row["route_file_blob_id"],
                "source_key": route_row["source_key"],
                "source_anchor": route_row["source_anchor"],
                "route_method": route_row["route_method"],
                "literal_uri": route_row["literal_uri"],
                "literal_route_name": queue_row["source"]["literal_route_name"],
                "action_expression": route_row["action_expression"],
                "statement_excerpt": route_row["statement_excerpt"],
                "statement_sha256": route_row["statement_sha256"],
                "framework_expanded_name_not_executed": True,
            },
            "controller_action": {
                "relation_class": "BOTH_LANES_IDENTICAL_EXACT_CONTROLLER_ACTION_REVIEW_CANDIDATE",
                "controller_fqcn": resolution["resolved_fqcn"],
                "primary_method_slice": primary,
                "transitive_local_helper_slices": helpers,
                "request_contracts": requests,
                "literal_inertia_page_callsites": inertia_callsites,
                "literal_inertia_page_callsite_count": len(inertia_callsites),
                "shared_source_review_packet_sha256": packet["source_review_packet_sha256"],
                "external_dependency_semantics_complete": False,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
            "frontend_caller_context": frontend_contexts[route_id],
            "feature_identity_projection": feature_projection(matrix_by_id[FEATURE_ID]),
            "collision_checks": {
                "previous_review_source_collision": False,
                "current_owner_source_collision": False,
                "existing_controller_action_bridge_collision": False,
                "prior_index_owner_context_present": True,
                "prior_index_owner_inheritance_authorized": False,
                "existing_caller_page_context_present": frontend_contexts[route_id].get(
                    "current_static_page_owner", False
                ),
                "existing_caller_page_inheritance_authorized": False,
                "excluded_sync_sibling_inheritance_authorized": False,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_ROUTE_ACTION",
                    "SHARED_RELATION",
                    "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL",
                    "EVIDENCE_GAP",
                ],
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
                "correctness_credit": False,
                "downstream_credit": False,
            },
            "evidence_digests": {
                "queue_record_sha256": queue_row["queue_record_sha256"],
                "route_manifest_record_sha256": canonical_json_sha256(route_row),
                "route_candidate_record_sha256": canonical_json_sha256(candidate),
                "route_decision_sha256": canonical_json_sha256(decision),
                "primary_method_slice_sha256": primary["review_slice"]["text_sha256"],
                "local_support_sha256": canonical_json_sha256(helpers),
                "request_support_sha256": canonical_json_sha256(requests),
                "frontend_caller_context_sha256": canonical_json_sha256(frontend_contexts[route_id]),
                "source_review_packet_sha256": packet["source_review_packet_sha256"],
            },
        }
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 6
    assert len({row["queue_id"] for row in records}) == 6
    assert len({row["route_source"]["route_record_id"] for row in records}) == 6
    assert len({row["action_key"] for row in records}) == 6
    assert Counter(row["review_partition"] for row in records) == {"A": 2, "B": 2, "C": 2}
    assert Counter(row["run090_original_partition"] for row in records) == {
        "A": 2,
        "B": 2,
        "C": 2,
    }
    assert {row["candidate_feature_id"] for row in records} == {FEATURE_ID}
    assert all(row["converged_identity"]["candidate_only"] for row in records)
    assert all(row["converged_identity"]["name_candidate_count"] == 1 for row in records)
    assert all(row["converged_identity"]["backend_candidate_count"] == 1 for row in records)
    assert sum(row["controller_action"]["literal_inertia_page_callsite_count"] for row in records) == 1
    assert sum(row["frontend_caller_context"]["literal_caller_count"] for row in records) == 5

    partitions: dict[str, dict[str, Any]] = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        assert len(assigned) == 2
        partitions[partition] = {
            "assigned_candidates": 2,
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "queue_ids": [row["queue_id"] for row in assigned],
            "methods": [row["controller_action"]["primary_method_slice"]["method"] for row in assigned],
            "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in assigned]),
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in assigned]),
            "shared_source_review_packet_sha256": packet["source_review_packet_sha256"],
            "fresh_reviewer_required": True,
        }

    feature_ids = {row["candidate_feature_id"] for row in records}
    assert feature_ids - current_owner_features == set()
    identity = {
        "candidate_id_list_sha256": canonical_list_sha256(
            [row["candidate_id"] for row in records]
        ),
        "queue_index_list_sha256": canonical_list_sha256(
            [str(row["queue_index_zero_based"]) for row in records]
        ),
        "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in records]),
        "canonical_key_list_sha256": canonical_list_sha256(
            [row["queue_canonical_key"] for row in records]
        ),
        "queue_id_canonical_key_pair_list_sha256": canonical_list_sha256(
            [f"{row['queue_id']}|{row['queue_canonical_key']}" for row in records]
        ),
        "route_record_id_list_sha256": canonical_list_sha256(
            [row["route_source"]["route_record_id"] for row in records]
        ),
        "literal_route_name_list_sha256": canonical_list_sha256(
            [row["route_source"]["literal_route_name"] for row in records]
        ),
        "source_key_list_sha256": canonical_list_sha256(
            [row["route_source"]["source_key"] for row in records]
        ),
        "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in records]),
        "feature_id_list_sha256": canonical_list_sha256(feature_ids),
        "new_feature_id_list_sha256": canonical_list_sha256(feature_ids - current_owner_features),
        "candidate_record_sha256_list_sha256": canonical_list_sha256(
            [row["candidate_record_sha256"] for row in records]
        ),
        "queue_record_sha256_list_sha256": canonical_list_sha256(
            [row["evidence_digests"]["queue_record_sha256"] for row in records]
        ),
        "records_sha256": canonical_json_sha256(records),
        "source_review_packet_sha256": packet["source_review_packet_sha256"],
        "prior_owner_context_sha256": canonical_json_sha256(prior_owner_context),
        "prior_bridge_context_sha256": canonical_json_sha256(prior_bridge_context),
        "excluded_sync_route_sha256": canonical_json_sha256(excluded_sync_route),
        "next_queue_record_sha256": next_row["queue_record_sha256"],
    }
    assert identity["new_feature_id_list_sha256"] == (
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    )
    expected_selection_identity = {
        "candidate_id_list_sha256": "d1f690f52a2e43287b1b2f119a7cd7f8d9b6ba3eca622860a401102e68a9f8b0",
        "queue_index_list_sha256": "c6748380bc15a7c90cd3d8a19c639039e6501ff90aac2d64524bcbba9fe2115e",
        "queue_id_list_sha256": "0ab1a5df6be68effc8e1d09302e154ce06100340ae35a0b988f471ff3d9e1b88",
        "canonical_key_list_sha256": "7759f09cbfc7d7965f56274be57a21e1211e709827b3050e3dc07b8c36112791",
        "route_record_id_list_sha256": "29faf99573a19511a686a6281d363916970ee99f1908fd0ec9c2daeafc9d0ff7",
        "literal_route_name_list_sha256": "9aa95297f83b1106607dd6447d3da6e2e4eb0250b1dc77d1a23e9ff075ace173",
        "queue_record_sha256_list_sha256": "ccf64df7d9014a0796e3f172a18c45a7aba5e78bc758265be51003ffb67eb97d",
        "excluded_sync_route_sha256": "d9571c0f0572917e7e75a9f740ce83f5093e6cf70e24d3f6a7522351a79593f2",
        "next_queue_record_sha256": "ebf85adb661b20cf542365a66e5bac407d7a072fb1627676d4e92ddd20bea933",
    }
    for key, value in expected_selection_identity.items():
        assert identity[key] == value, key

    excluded_sync_context = {
        "route_record_id": EXCLUDED_SYNC_ROUTE_ID,
        "route_file": excluded_sync_route["route_file"],
        "source_anchor": excluded_sync_route["source_anchor"],
        "literal_uri": excluded_sync_route["literal_uri"],
        "literal_route_name": excluded_sync_route["direct_name_literal"],
        "action_expression": excluded_sync_route["action_expression"],
        "candidate_relation": excluded_sync_candidate["relation_comparison"],
        "name_candidate_feature_ids": excluded_sync_candidate["name_relation"]["candidate_feature_ids"],
        "backend_candidate_feature_ids": excluded_sync_candidate["backend_method_relation"]["candidate_feature_ids"],
        "controller_method": excluded_sync_candidate["backend_method_relation"]["resolution"]["method"],
        "run090_queue_record_present": False,
        "selected_for_run133": False,
        "semantic_outcome_awarded": False,
        "ownership_credit_awarded": False,
        "bridge_credit_awarded": False,
        "correctness_or_downstream_credit_awarded": False,
        "boundary": (
            "This BACKEND_ONLY sibling is absent from RUN-090 and cannot be smuggled into a direct-exact queue "
            "cohort. It remains an explicit residual source row for a separately bounded adjudication."
        ),
    }

    return {
        "schema_version": "run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21-v1",
        "run_id": "RUN-133-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-COHORT-WAVE-21",
        "status": "SIX_FINANCE_ACCOUNTING_INTEGRATION_ROUTE_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
        "generated_on": "2026-08-26",
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
            "prompt_path": str(PROMPT_PATH),
            "prompt_sha256": PROMPT_SHA256,
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. The provider tenant_id is a "
            "Xero tenant identifier or MYOB company-file URI; organization_id is legacy schema or organisational "
            "context. Neither is a SaaS tenant boundary. Roles, exact permissions, approved Sites, canonical record "
            "ownership, direct-object concealment, privacy, secret handling, provider semantics, and concurrency "
            "remain separate questions and receive zero credit here."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "From the committed RUN-131/RUN-132 boundary, freeze all and only queue indices 71 through 76: "
                "RUN090-ROUTE-0072 through RUN090-ROUTE-0077. Require singleton CAP-FIN-ACCOUNTING-INTEGRATION-"
                "CONFIGURATION exact-name identity, identical singleton backend identity, unique controller-method "
                "resolution, no prior review, no current owner, and no controller-action bridge collision."
            ),
            "already_reviewed_index_rule": (
                "Queue index 70 and RUN077-ROUTE-0592 were reviewed in RUN-091/RUN-092 and are existing owner "
                "context only. The index route, index bridge, and Index page cannot be reviewed or credited again."
            ),
            "backend_only_sync_rule": (
                "RUN077-ROUTE-0595 integrations.sync is BACKEND_ONLY and absent from RUN-090. It is explicit "
                "context only, adds no seventh candidate, and remains residual with zero credit."
            ),
            "next_boundary_rule": (
                "Queue index 77 is RUN090-ROUTE-0078 / RUN077-ROUTE-0634 invoices.index for CAP-FIN-BILLING-"
                "INVOICE-LIFECYCLE. It and every later row are outside RUN-133."
            ),
            "page_rule": (
                "The Index page is already owned by the selected H feature. The Mapping page is already owned by "
                "CAP-FIN-XERO-ACCOUNTING-SYNC. Caller and render context cannot inherit, duplicate, or reassign "
                "either page owner; RUN-133 contains zero page records."
            ),
            "prohibited_inheritance": [
                "route group, adjacency, or queue proximity",
                "already-reviewed index route or controller-action bridge",
                "backend-only sync sibling",
                "shared controller, model, service, or provider containment",
                "identical static candidate lanes",
                "existing Index or Mapping page ownership",
                "frontend caller presence or absence",
                "finance.admin middleware declaration",
                "legacy organization_id or provider tenant_id context",
                "unexecuted test presence",
                "runtime, browser, benchmark, Pass, final-finding, or completion",
            ],
        },
        "current_baseline": expected_baseline,
        "source_review_packet": packet,
        "prior_owner_context_non_inheritable": prior_owner_context,
        "prior_bridge_context_non_inheritable": prior_bridge_context,
        "excluded_backend_only_sync_context": excluded_sync_context,
        "next_queue_boundary": {
            "queue_index_zero_based": next_index,
            "queue_id": next_row["queue_id"],
            "route_record_id": next_row["source_record_id"],
            "candidate_feature_id": next_row["candidate_feature_id"],
            "selected_for_run133": False,
            "credit_awarded": False,
        },
        "page_context_boundary": {
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
        },
        "semantic_review_focus": {
            "partition_A_store_update": [
                "trace validation, provider uniqueness, actor context, arbitrary settings, status, and redirects",
                "separate the absent literal update caller from dead-route adjudication",
                "review soft-delete-aware validation against the physical unique constraint",
            ],
            "partition_B_test_destroy": [
                "trace organization authorization, provider selection, Xero/MYOB test semantics, errors, and secrets",
                "trace disconnect semantics, remote token revocation, queued work races, and soft-delete replay",
                "do not convert a successful response or provider call into correctness credit",
            ],
            "partition_C_mapping_update": [
                "trace integration binding, local-account scope, existing cross-feature page ownership, and projection",
                "review mapping keys and values for canonical local-account membership and provider validity",
                "preserve the Mapping page's existing D-feature owner without route-to-page inheritance",
            ],
            "cohort_synthesis": (
                "Determine an explicit outcome for every route action while keeping the backend-only sync sibling, "
                "the already-owned index chain, page ownership, and all correctness dimensions separate."
            ),
        },
        "risk_register": [
            {
                "risk_id": "RUN133-RISK-DIRECT-OBJECT-CONCEALMENT",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:106-108",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
                ],
                "observation": (
                    "Selected bound-record actions compare legacy organization_id and abort 403. Fresh review must "
                    "separately establish canonical record scope, approved-Site rules, and direct-ID concealment."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-PROVIDER-TENANT-SEMANTICS",
                "observed_loci": [
                    "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:13-16",
                    "docs/architecture/single-tenant-application.md:1-13",
                ],
                "observation": (
                    "tenant_id is an external provider identifier, not application tenancy. organization_id is legacy "
                    "organisational context; neither replaces roles, Sites, ownership, or privacy controls."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-SOFT-DELETE-RECONNECT",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:76-97",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164-172",
                    "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:29-32",
                ],
                "observation": (
                    "Validation ignores soft-deleted rows while the physical organization/provider unique key does "
                    "not include deleted_at; disconnect also shows no external revocation in the selected slice."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-SETTINGS-MAPPING-VALIDATION",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:84-87",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:110-117",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:218-228",
                ],
                "observation": (
                    "Settings accept an unconstrained array and mapping keys/values are strings without selected-slice "
                    "proof that local account IDs belong to the canonical scope or external IDs are valid."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-ERROR-AND-SECRET-EXPOSURE",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143-158",
                    "app/Domain/Finance/Models/FinAccountingIntegration.php:19-51",
                    "app/Models/Concerns/AuditableChanges.php:10-64",
                ],
                "observation": (
                    "Connection testing can return exception text, and the auditable model includes provider fields, "
                    "tokens, settings, and mappings. Encryption/hidden casts do not by themselves prove safe logs or UI errors."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-MYOB-CAPABILITY-MISMATCH",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:77-85",
                    "app/Domain/Finance/Services/AccountingSyncProviders/MyobSyncProvider.php:10-97",
                    "resources/js/pages/finance/Integrations/Index.tsx:160-170",
                ],
                "observation": (
                    "The selected creation UI/controller admit MYOB while its provider declares synchronization "
                    "unsupported and returns false for connection tests. Ownership and production correctness remain separate."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-CROSS-FEATURE-PAGE",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:178-208",
                    "resources/js/pages/finance/Integrations/Mapping.tsx:63-122",
                ],
                "observation": (
                    "The selected mapping route renders a page already frozen to CAP-FIN-XERO-ACCOUNTING-SYNC. "
                    "This cohort cannot silently move or duplicate that page under the H feature."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-MISSING-UPDATE-CALLER",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:103-121",
                    "resources/js/pages/finance/Integrations/Index.tsx:98-510",
                    "resources/js/pages/finance/Integrations/Mapping.tsx:63-122",
                ],
                "observation": (
                    "No literal caller for integrations.update was found in the two frozen integration pages. "
                    "Absence is review evidence, not an automatic dead-route decision."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-SYNC-EXCLUSION",
                "observed_loci": [
                    "routes/finance.php:607",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:123-138",
                ],
                "observation": (
                    "The canonical job includes manual sync, but integrations.sync is BACKEND_ONLY and absent from "
                    "RUN-090. RUN-133 neither reviews nor credits that sibling."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN133-RISK-UNEXECUTED-TESTS",
                "observed_loci": [
                    "tests/Browser/Finance/FinanceIntegrationMappingInteractionTest.php:8-72",
                    "tests/Browser/Finance/FinanceMiscTest.php:126-134",
                    "tests/Feature/Finance/XeroAccountingSyncProviderTest.php:1-285",
                ],
                "observation": (
                    "Frozen tests provide static context only. They were not executed for RUN-133 and do not prove "
                    "all six actions, foreign-object denial, Sites, error concealment, disconnect, or concurrency."
                ),
                "credit_authorized": False,
            },
        ],
        "stop_rules": [
            "Abort materialization on any checkpoint, tree, input, source-file blob, queue identity, or source-record drift.",
            "Classify unresolved material dependency, authorization, Site, direct-object, privacy, secret, provider, mapping, lifecycle, or concurrency semantics as EVIDENCE_GAP.",
            "Do not inherit an outcome from the index route, sync sibling, route group, controller, pages, frontend callers, or matrix user job.",
            "Do not integrate any OWNER_ROUTE_ACTION until all three partition decisions and a fresh cohort synthesis reconcile all six rows.",
            "Preserve every non-owner outcome and every zero-credit boundary; never coerce a six-row cohort into six owners.",
        ],
        "counts": {
            "candidate_route_actions": 6,
            "candidate_route_records": 6,
            "candidate_controller_action_bridges": 6,
            "candidate_page_records": 0,
            "distinct_feature_ids": 1,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "both_lanes_identical_candidates": 6,
            "name_only_candidates": 0,
            "controller_files": 1,
            "frontend_literal_caller_contexts": 5,
            "selected_routes_without_literal_caller_in_frozen_pages": 1,
            "selected_literal_controller_render_callsites": 1,
            "existing_distinct_caller_or_render_pages": 2,
            "prior_owned_route_context_rows": 1,
            "prior_owned_page_context_rows": 2,
            "prior_owned_controller_action_bridge_context_rows": 1,
            "excluded_backend_only_sync_context_rows": 1,
            "required_source_files": packet["required_source_file_count"],
            "material_dependency_method_slices": packet["material_dependency_method_slice_count"],
            "new_feature_ids": 0,
            "queue_pending_before": 399,
            "selected_pending_queue_surfaces": 6,
            "queue_unselected_pending": 393,
            "selected_queue_surfaces_still_pending": 6,
            "current_reviewed_queue_surface_rows": 108,
            "current_pending_queue_surface_rows": 399,
            "ownership_credit_awarded": 0,
            "controller_action_bridge_credit_awarded": 0,
            "page_ownership_credit_awarded": 0,
            "site_authorization_credit_awarded": 0,
            "permission_credit_awarded": 0,
            "direct_object_credit_awarded": 0,
            "privacy_credit_awarded": 0,
            "secret_handling_credit_awarded": 0,
            "provider_correctness_credit_awarded": 0,
            "mapping_integrity_credit_awarded": 0,
            "lifecycle_credit_awarded": 0,
            "concurrency_credit_awarded": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "final_finding_credit": 0,
            "completion_credit": 0,
            "new_queue_review_credit": 0,
            "matrix_mutation_credit": 0,
            "application_source_mutation_credit": 0,
            "release_credit": 0,
        },
        "identity": identity,
        "review_partitions": partitions,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_partition_reviews": 3,
            "required_cohort_synthesis": 1,
            "required_reviews": 4,
            "four_fresh_reviewers_required": True,
            "reviewers_must_be_fresh_from_discovery_producer": True,
            "cohort_synthesizer_must_be_fresh_from_all_partition_reviewers": True,
            "required_outcome_per_candidate": True,
            "allowed_outcomes": [
                "OWNER_ROUTE_ACTION",
                "SHARED_RELATION",
                "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL",
                "EVIDENCE_GAP",
            ],
            "integration_rule": (
                "Only an explicit OWNER_ROUTE_ACTION may later add one route owner and one controller-action "
                "bridge. Every other outcome adds neither. The sync sibling and pages remain excluded; correctness, "
                "runtime, browser, test, benchmark, Pass, final-finding, and completion remain zero."
            ),
            "page_owner_records_authorized": 0,
            "excluded_sync_owner_records_authorized": 0,
            "ownership_integration_authorized": False,
            "downstream_credit_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "outcome_variables": "O owner, S shared, A alias, D dead, E evidence gap",
            "equation": "O + S + A + D + E = 6",
            "bounded_sources": "3929 = (654 + O) + (3275 - O)",
            "owner_surfaces": "654 + O = (297 + O) routes + 357 pages",
            "current_queue": "507 = 108 reviewed + 399 pending; 399 pending = 6 frozen candidates + 393 other pending",
            "post_review_queue_projection_only": "507 = (108 + 6 reviewed) + 393 pending",
            "post_review_outcome_projection_only": "114 = (86 + O) owner + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "post_review_without_ownership_projection_only": "421 - O = 393 pending + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "route_universe": (
                "3218 = (297 + O) owner + (12 + S) shared + (5 + A) alias + D dead + "
                "(2904 - O - S - A - D) residual; 7 + E gaps are tagged within residual"
            ),
            "pages": "711 = 357 owner + 9 shared + 345 residual; one earlier gap remains tagged within residual",
            "controller_action_bridges": "85 + O",
            "distinct_feature_ids": "256 regardless of O because the selected feature is already represented",
            "feature_sets": "256 = 234 H + 22 D; route 62, page 242, overlap 48; unchanged regardless of outcome",
            "matrix_mutation": "0 rows and 0 cells changed",
            "bounded_ownership_percent": "100 * (654 + O) / 3929; no projection is current credit",
            "all_owner_projection_only": {
                "source_owner_records": 660,
                "route_owner_records": 303,
                "page_owner_records": 357,
                "static_controller_action_bridges": 91,
                "bounded_static_source_residual_records": 3269,
                "residual_explicit_unmapped_routes": 2898,
                "bounded_static_source_ownership_percent": "16.798167",
                "reviewed_queue_surface_rows": 114,
                "owner_queue_surface_rows": 92,
                "pending_unreviewed_queue_surface_rows": 393,
                "queue_surfaces_without_ownership": 415,
                "projection_credit_awarded": False,
            },
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "route_action_candidate_cohort": True,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "page_ownership": False,
            "excluded_sync_ownership": False,
            "new_queue_review": False,
            "navigation": False,
            "canonical_object_ownership": False,
            "matrix_mutation": False,
            "application_source_mutation": False,
            "responsive_application": False,
            "visual_application_workflow": False,
            "release": False,
            "prior_owner_context_preserved": True,
            "prior_owner_context_inherited": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "secret_handling_correctness": False,
            "provider_correctness": False,
            "mapping_integrity_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json",
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
                "candidate_route_actions": payload["counts"]["candidate_route_actions"],
                "review_partitions": {
                    key: value["assigned_candidates"]
                    for key, value in payload["review_partitions"].items()
                },
                "excluded_sync_context_rows": payload["counts"][
                    "excluded_backend_only_sync_context_rows"
                ],
                "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
                "completion_credit": payload["counts"]["completion_credit"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
