#!/usr/bin/env python3
"""Freeze RUN-137's first pending invoice-index route action without an outcome.

The reviewed RUN-135/RUN-136 boundary leaves 393 RUN-090 queue rows pending.
This producer selects zero-based queue index 77 only. Existing invoice pages,
static path callers, adjacent invoice routes, and unexecuted tests are context;
none can contribute inherited ownership or correctness credit.
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
TEMPLATE_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py"
)
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

CHECKPOINT_COMMIT = "9da5bbfa5a575f272cee2389ab5de5178e063c03"
CHECKPOINT_TREE = "4b84050bc4e960a17cf8321313677cfe53134c28"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

FEATURE_ID = "CAP-FIN-BILLING-INVOICE-LIFECYCLE"
QUEUE_INDEX = 77
QUEUE_ID = "RUN090-ROUTE-0078"
ROUTE_ID = "RUN077-ROUTE-0634"
NEXT_QUEUE_ID = "RUN090-ROUTE-0079"
NEXT_ROUTE_ID = "RUN077-ROUTE-0669"
INDEX_PAGE_ID = "PAGE-ROOT-B4964DF8343DF25A"
SHOW_PAGE_ID = "PAGE-ROOT-E1ACF667B368A747"

spec = importlib.util.spec_from_file_location("run133_template", TEMPLATE_GENERATOR)
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
    "template_generator": TEMPLATE_GENERATOR,
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "task_contract": AUDIT_DIR / "task-scripts/cap-fin-billing-invoice-lifecycle.md",
    "manifest": BASE.INPUT_PATHS["manifest"],
    "classification": BASE.INPUT_PATHS["classification"],
    "candidate_manifest": BASE.INPUT_PATHS["candidate_manifest"],
    "candidate_review": BASE.INPUT_PATHS["candidate_review"],
    "ownership_ledger": BASE.INPUT_PATHS["ownership_ledger"],
    "direct_queue_generator": BASE.INPUT_PATHS["direct_queue_generator"],
    "direct_queue": BASE.INPUT_PATHS["direct_queue"],
    "run133_cohort": AUDIT_DIR / "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json",
    "run134_overlay": AUDIT_DIR / "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
    "run134_review": AUDIT_DIR / "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
    "run135_reporting": AUDIT_DIR / "evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json",
    "run136_dashboard": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json",
}
for input_name in (*BASE.COHORT_NAMES, *BASE.OVERLAY_NAMES):
    INPUT_PATHS[input_name] = BASE.INPUT_PATHS[input_name]

EXPECTED_INPUT_SHA256 = {
    "template_generator": "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af",
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "task_contract": "60fe7723e9ebb795dd94cd19478fb88e365ecd3cd6f8dee3c7d60e8640fa8e9b",
    "manifest": BASE.EXPECTED_INPUT_SHA256["manifest"],
    "classification": BASE.EXPECTED_INPUT_SHA256["classification"],
    "candidate_manifest": BASE.EXPECTED_INPUT_SHA256["candidate_manifest"],
    "candidate_review": BASE.EXPECTED_INPUT_SHA256["candidate_review"],
    "ownership_ledger": BASE.EXPECTED_INPUT_SHA256["ownership_ledger"],
    "direct_queue_generator": BASE.EXPECTED_INPUT_SHA256["direct_queue_generator"],
    "direct_queue": BASE.EXPECTED_INPUT_SHA256["direct_queue"],
    "run133_cohort": "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4",
    "run134_overlay": "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669",
    "run134_review": "da3107cdcbb4ab286c208f85d994676d00f933d4002a966fb89773f8ef0857d3",
    "run135_reporting": "af70461527e7b22855b0a7917121112ca973fe4e88450b6b87ef0b5ae39d99da",
    "run136_dashboard": "24838333225819640bc767d7f5149aaaadcfa11377e4035e985af314fc549d1e",
}
for input_name in (*BASE.COHORT_NAMES, *BASE.OVERLAY_NAMES):
    EXPECTED_INPUT_SHA256[input_name] = BASE.EXPECTED_INPUT_SHA256[input_name]

SOURCE_FILE_PURPOSES = {
    "routes/web.php": ("routes/web.php:369", "finance route loader"),
    "routes/finance.php": ("routes/finance.php:62; routes/finance.php:674-709", "finance prefix/name group, selected index route, permission, and adjacent invoice boundary"),
    "app/Domain/Finance/Http/Controllers/InvoiceController.php": ("app/Domain/Finance/Http/Controllers/InvoiceController.php:1-792", "complete controller; selected index action and local helper closure"),
    "app/Domain/Finance/Models/FinInvoice.php": ("app/Domain/Finance/Models/FinInvoice.php:1-208", "invoice fields, relations, organization/status scopes, and soft deletion"),
    "app/Domain/Finance/Models/FinInvoiceLine.php": ("app/Domain/Finance/Models/FinInvoiceLine.php:1-54", "eager-loaded line projection context"),
    "app/Domain/Finance/Models/FinPaymentAllocation.php": ("app/Domain/Finance/Models/FinPaymentAllocation.php:1-111", "outstanding-balance aggregation, Site, type, and integrity context"),
    "app/Domain/Finance/Models/FinTaxRate.php": ("app/Domain/Finance/Models/FinTaxRate.php:1-53", "manager-only tax-rate reference projection"),
    "app/Models/Client.php": ("app/Models/Client.php:1-430", "Client Site ownership and invoice relation context"),
    "app/Services/UserSiteAccessService.php": ("app/Services/UserSiteAccessService.php:70-153; 1076-1087", "approved-Site derivation and Client query scope"),
    "app/Models/User.php": ("app/Models/User.php:346-437", "permission evaluation"),
    "app/Domain/Finance/Policies/FinInvoicePolicy.php": ("app/Domain/Finance/Policies/FinInvoicePolicy.php:1-30", "permission-only invoice policy context"),
    "app/Providers/AuthServiceProvider.php": ("app/Providers/AuthServiceProvider.php:137-216", "invoice policy registration context"),
    "bootstrap/app.php": ("bootstrap/app.php:1-96", "permission middleware alias registration"),
    "app/Http/Middleware/EnsurePermission.php": ("app/Http/Middleware/EnsurePermission.php:1-29", "literal finance.ar.view enforcement path"),
    "app/Http/Middleware/HandleInertiaRequests.php": ("app/Http/Middleware/HandleInertiaRequests.php:990-1004", "shared frontend AR permission projection"),
    "resources/js/pages/finance/invoices/Index.tsx": ("resources/js/pages/finance/invoices/Index.tsx:1-570", "existing rendered page and same-path filter caller context"),
    "resources/js/pages/finance/invoices/Show.tsx": ("resources/js/pages/finance/invoices/Show.tsx:92-190", "existing matrix-declared sibling page context only"),
    "resources/js/components/finance/receivables-hub.tsx": ("resources/js/components/finance/receivables-hub.tsx:38-58", "permission-gated static invoice navigation context"),
    "resources/js/components/app-sidebar.tsx": ("resources/js/components/app-sidebar.tsx:1848-1861", "sidebar invoice navigation context"),
    "resources/js/pages/operations/service-agreements/Show.tsx": ("resources/js/pages/operations/service-agreements/Show.tsx:2068-2075", "cross-module filtered invoice-list path context"),
    "resources/js/pages/finance/billing/Index.tsx": ("resources/js/pages/finance/billing/Index.tsx:216-226", "billing-to-invoice-list path context"),
    "database/migrations/2026_03_28_004100_create_fin_invoices_table.php": ("database/migrations/2026_03_28_004100_create_fin_invoices_table.php:1-53", "base invoice schema"),
    "database/migrations/2026_03_28_004200_create_fin_invoice_lines_table.php": ("database/migrations/2026_03_28_004200_create_fin_invoice_lines_table.php:1-36", "line schema used by the page projection"),
    "database/migrations/2026_03_28_002100_create_fin_payment_allocations_table.php": ("database/migrations/2026_03_28_002100_create_fin_payment_allocations_table.php:1-37", "base allocation schema"),
    "database/migrations/2026_05_02_100000_add_operations_metadata_to_fin_invoices.php": ("database/migrations/2026_05_02_100000_add_operations_metadata_to_fin_invoices.php:1-54", "invoice Client/source linkage"),
    "database/migrations/2026_08_14_000064_add_finance_payment_global_site_permission.php": ("database/migrations/2026_08_14_000064_add_finance_payment_global_site_permission.php:1-230", "allocation Site and integrity schema plus global-Site permission"),
    "database/seeders/RbacSeeder.php": ("database/seeders/RbacSeeder.php:524-545; 779-790; 838-849", "AR permission and role assignment context"),
    "database/seeders/FinancePermissionsSeeder.php": ("database/seeders/FinancePermissionsSeeder.php:1-129", "AR permission declaration context"),
    "docs/architecture/single-tenant-application.md": ("docs/architecture/single-tenant-application.md:1-21", "canonical one-organisation multi-Site boundary"),
    "tests/Feature/Finance/InvoiceIndexReceiptTest.php": ("tests/Feature/Finance/InvoiceIndexReceiptTest.php:1-96", "unexecuted selected-index balance and projection context"),
    "tests/Feature/Finance/CommercialFinanceSiteBoundaryTest.php": ("tests/Feature/Finance/CommercialFinanceSiteBoundaryTest.php:1-139", "unexecuted adjacent commercial-finance Site context"),
    "tests/Feature/Finance/FinanceNavMoveTest.php": ("tests/Feature/Finance/FinanceNavMoveTest.php:1-70", "unexecuted effective route-name and navigation context"),
    "tests/Browser/Finance/FinanceInvoicesTest.php": ("tests/Browser/Finance/FinanceInvoicesTest.php:1-29", "unexecuted invoice browser context"),
}

DEPENDENCY_METHOD_SPECS = [
    ("app/Domain/Finance/Models/FinInvoice.php", "scopeForOrganization"),
    ("app/Domain/Finance/Models/FinInvoice.php", "scopeOfStatus"),
    ("app/Domain/Finance/Models/FinPaymentAllocation.php", "scopeForOrganization"),
    ("app/Domain/Finance/Models/FinTaxRate.php", "scopeForOrganization"),
    ("app/Domain/Finance/Models/FinTaxRate.php", "scopeActive"),
    ("app/Services/UserSiteAccessService.php", "applyClientScope"),
    ("app/Services/UserSiteAccessService.php", "accessibleSiteIds"),
    ("app/Services/UserSiteAccessService.php", "canBypass"),
    ("app/Models/User.php", "canDo"),
    ("app/Domain/Finance/Policies/FinInvoicePolicy.php", "viewAny"),
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
    assert PROMPT_PATH.is_file() and sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, target in INPUT_PATHS.items():
        assert target.is_file(), target
        assert sha256_file(target) == EXPECTED_INPUT_SHA256[name], name
    for relative in SOURCE_FILE_PURPOSES:
        assert (REPO / relative).is_file(), relative
        assert git("rev-parse", f"HEAD:{relative}") == git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"), relative


def source_review_packet() -> dict[str, Any]:
    required_files = [
        {
            "path": relative,
            "sha256": sha256_file(REPO / relative),
            "blob_id": git("rev-parse", f"HEAD:{relative}"),
            "application_commit_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
            "review_loci": [part.strip() for part in loci.split(";")],
            "purpose": purpose,
        }
        for relative, (loci, purpose) in SOURCE_FILE_PURPOSES.items()
    ]
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
        "known_excluded_expansion_candidates": [
            "resources/js/components/finance/new-invoice-dialog.tsx",
            "resources/js/components/finance/record-receipt-dialog.tsx",
            "app/Domain/Finance/Http/Controllers/PaymentAllocationController.php",
            "app/Domain/Finance/Services/AccountsReceivableService.php",
            "resources/js/pages/finance/invoices/Create.tsx",
            "resources/js/pages/finance/invoices/Edit.tsx",
        ],
        "source_review_complete": False,
        "source_packet_completeness_claimed": False,
        "material_dependency_semantics_complete": False,
        "known_expansion_candidates_adjudicated": False,
        "unexecuted_test_context_is_runtime_evidence": False,
        "review_rule": (
            "Review the selected index action and every frozen material dependency. Expand the packet when a known "
            "or newly discovered dependency is outcome-material. Any unresolved Site, permission, privacy, query, "
            "projection, page, caller, lifecycle, or test dependency requires EVIDENCE_GAP."
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
    run133 = load_json(INPUT_PATHS["run133_cohort"])
    run134 = load_json(INPUT_PATHS["run134_overlay"])
    run134r = load_json(INPUT_PATHS["run134_review"])
    run135 = load_json(INPUT_PATHS["run135_reporting"])
    run136 = load_json(INPUT_PATHS["run136_dashboard"])
    cohorts = [load_json(INPUT_PATHS[name]) for name in BASE.COHORT_NAMES] + [run133]
    overlays = [load_json(INPUT_PATHS[name]) for name in BASE.OVERLAY_NAMES] + [run134]

    assert candidate_review["verdict"]["decision"] == "GO"
    assert run134r["decision"]["verdict"] == "GO"
    assert run136["verification"]["state"] == "GO"
    expected_baseline = {
        "source_owner_records": 660, "route_owner_records": 303, "page_owner_records": 357,
        "distinct_feature_ids": 256, "distinct_H_feature_ids": 234, "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 62, "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 48, "static_controller_action_bridges": 91,
        "bounded_static_source_denominator": 3929, "bounded_static_source_ownership_percent": "16.798167",
        "bounded_static_source_residual_records": 3269, "residual_explicit_unmapped_routes": 2898,
        "semantic_shared_routes": 12, "reviewed_alias_routes": 5, "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 7, "residual_unadjudicated_page_roots": 345,
        "semantic_shared_page_roots": 9, "reviewed_alias_page_roots": 0, "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1, "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 114, "owner_queue_surface_rows": 92,
        "shared_queue_surface_rows": 10, "alias_queue_surface_rows": 5, "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7, "pending_unreviewed_queue_surface_rows": 393,
        "queue_surfaces_without_ownership": 415, "matrix_rows_changed": 0, "matrix_cells_changed": 0,
    }
    for key, value in expected_baseline.items():
        assert run135["counts"][key] == value, key

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_by_id = index_unique(route_rows, "route_record_id")
    decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    candidate_by_id = index_unique(candidates["route_static_candidate_census"]["records"], "route_record_id")

    owner_rows = list(ownership["records"])
    owner_origin = {row["source_record_id"]: "ownership_ledger" for row in owner_rows}
    bridge_rows: list[dict[str, Any]] = []
    bridge_ids: set[str] = set()
    for overlay_index, overlay in enumerate(overlays):
        origin = (*BASE.OVERLAY_NAMES, "run134_overlay")[overlay_index]
        for row in overlay["overlay_source_records"]:
            assert row["source_record_id"] not in owner_origin
            owner_rows.append(row)
            owner_origin[row["source_record_id"]] = origin
        for field in ("static_controller_action_bridges", "new_static_controller_action_bridges"):
            for row in overlay.get(field, []):
                assert row["bridge_id"] not in bridge_ids
                bridge_ids.add(row["bridge_id"])
                bridge_rows.append(row)
    owner_by_id = index_unique(owner_rows, "source_record_id")
    assert len(owner_rows) == 660
    assert Counter(row["surface"] for row in owner_rows) == {"ROUTE_SOURCE_RECORD": 303, "PAGE_ROOT_SOURCE_RECORD": 357}
    assert len(bridge_rows) == 91
    assert ROUTE_ID not in owner_by_id
    assert FEATURE_ID in {row["feature_id"] for row in owner_rows if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    assert FEATURE_ID not in {row["feature_id"] for row in owner_rows if row["surface"] == "ROUTE_SOURCE_RECORD"}

    reviewed_route_ids: set[str] = set()
    for cohort in cohorts:
        reviewed_route_ids |= cohort_route_ids(cohort)
    assert len(reviewed_route_ids) == 112
    assert ROUTE_ID not in reviewed_route_ids
    assert run135["verified_noninheritance_boundary"]["next_queue_index_zero_based"] == QUEUE_INDEX
    assert run135["verified_noninheritance_boundary"]["next_queue_id"] == QUEUE_ID
    assert run135["verified_noninheritance_boundary"]["next_route_record_id"] == ROUTE_ID
    assert run135["verified_noninheritance_boundary"]["next_boundary_selected_or_credited"] is False

    queue_row = queue["records"][QUEUE_INDEX]
    assert queue_row["queue_id"] == QUEUE_ID
    assert queue_row["source_record_id"] == ROUTE_ID
    assert queue_row["candidate_feature_id"] == FEATURE_ID
    assert queue_row["queue_record_sha256"] == "ebf85adb661b20cf542365a66e5bac407d7a072fb1627676d4e92ddd20bea933"
    next_row = queue["records"][QUEUE_INDEX + 1]
    assert next_row["queue_id"] == NEXT_QUEUE_ID and next_row["source_record_id"] == NEXT_ROUTE_ID

    route_row = route_by_id[ROUTE_ID]
    decision = decision_by_id[ROUTE_ID]
    candidate = candidate_by_id[ROUTE_ID]
    backend = candidate["backend_method_relation"]
    resolution = backend["resolution"]
    assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
    assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert queue_row["secondary_lane"]["relation_comparison"] == "NAME_ONLY"
    assert decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
    assert candidate["relation_comparison"] == "NAME_ONLY"
    assert candidate["name_relation"]["candidate_feature_ids"] == [FEATURE_ID]
    assert backend["candidate_count"] == 0 and backend["candidate_feature_ids"] == []
    assert resolution["controller_file"] == "app/Domain/Finance/Http/Controllers/InvoiceController.php"
    assert resolution["method"] == "index" and resolution["definition_line"] == 37
    assert sha256_file(REPO / resolution["controller_file"]) == resolution["controller_file_sha256"]

    existing_bridge_key = (resolution["controller_file"], "index", FEATURE_ID)
    assert existing_bridge_key not in {
        (row["controller_file"], row["method"], row["feature_id"]) for row in bridge_rows
    }
    page_context = []
    for page_id, relation in ((INDEX_PAGE_ID, "SELECTED_RENDER_PAGE"), (SHOW_PAGE_ID, "MATRIX_DECLARED_SIBLING_PAGE")):
        row = owner_by_id[page_id]
        assert row["feature_id"] == FEATURE_ID and owner_origin[page_id] == "ownership_ledger"
        page_context.append({
            "relation": relation, "source_record_id": page_id, "source_record_key": row["source_record_key"],
            "feature_id": row["feature_id"], "page_file": row["page_source"]["page_file"],
            "owner_row_id": row["mapping_id"], "owner_row_sha256": row["ledger_row_sha256"],
            "current_static_page_owner_credit_preserved": True, "ownership_inheritable_to_run137": False,
            "route_or_correctness_credit_inheritable_to_run137": False,
        })

    static_path_contexts = [
        exact_source_line("resources/js/pages/finance/invoices/Index.tsx", "{ title: 'Invoices', href: '/finance/invoices' },"),
        exact_source_line("resources/js/pages/finance/invoices/Index.tsx", "router.get('/finance/invoices', params, {"),
        exact_source_line("resources/js/pages/finance/invoices/Index.tsx", "router.get('/finance/invoices', {}, { preserveState: true });"),
        exact_source_line("resources/js/components/finance/receivables-hub.tsx", "href: '/finance/invoices',"),
        exact_source_line("resources/js/components/app-sidebar.tsx", "href: '/finance/invoices',"),
        exact_source_line("resources/js/pages/operations/service-agreements/Show.tsx", "href={`/finance/invoices?client_id="),
        exact_source_line("resources/js/pages/finance/billing/Index.tsx", '<Link href="/finance/invoices">'),
    ]
    for row in static_path_contexts:
        row["context_only"] = True
        row["route_or_page_ownership_inheritable"] = False

    primary = semantic_slice(resolution["controller_file"], "index")
    helpers = transitive_local_helper_slices(resolution["controller_file"], "index", primary["review_slice"]["text"])
    requests = request_contracts_for_slice(resolution["controller_file"], primary["review_slice"]["text"])
    render_callsite = {
        **exact_source_line(resolution["controller_file"], "return Inertia::render('finance/invoices/Index', ["),
        "render_name": "finance/invoices/Index", "existing_page_record_id": INDEX_PAGE_ID,
        "existing_page_feature_id": FEATURE_ID, "page_owner_inheritance_authorized": False,
        "page_reassignment_authorized": False,
    }
    packet = source_review_packet()
    action_key = f"{ROUTE_ID}|{resolution['controller_file']}:index|{FEATURE_ID}"
    record: dict[str, Any] = {
        "candidate_id": "RUN137-FINANCE-INVOICE-INDEX-ROUTE-ACTION-01",
        "action_key": action_key,
        "run090_original_partition": queue_row["review_partition"],
        "queue_index_zero_based": QUEUE_INDEX,
        "queue_id": QUEUE_ID,
        "queue_canonical_key": queue_row["canonical_key"],
        "candidate_feature_id": FEATURE_ID,
        "name_only_identity": {
            "direct_identity": queue_row["direct_identity"], "relation_comparison": "NAME_ONLY",
            "name_candidate_count": 1, "name_candidate_feature_ids": [FEATURE_ID],
            "backend_candidate_count": 0, "backend_candidate_feature_ids": [],
            "backend_candidate_absence_is_not_negative_proof": True,
            "unique_controller_resolution_is_review_context_not_feature_identity": True,
            "candidate_only": True,
        },
        "route_source": {
            "route_record_id": ROUTE_ID, "route_file": route_row["route_file"],
            "route_file_sha256": route_row["route_file_sha256"], "route_file_blob_id": route_row["route_file_blob_id"],
            "source_key": route_row["source_key"], "source_anchor": route_row["source_anchor"],
            "route_method": route_row["route_method"], "literal_uri": route_row["literal_uri"],
            "literal_route_name": queue_row["source"]["literal_route_name"],
            "action_expression": route_row["action_expression"], "statement_excerpt": route_row["statement_excerpt"],
            "statement_sha256": route_row["statement_sha256"],
            "static_group_context": {"uri_prefix": "finance", "name_prefix": "finance.", "derived_uri": "/finance/invoices", "derived_name": "finance.invoices.index", "framework_registration_executed": False},
        },
        "controller_action": {
            "relation_class": "NAME_ONLY_EXACT_CONTROLLER_ACTION_REVIEW_CANDIDATE",
            "controller_fqcn": resolution["resolved_fqcn"], "primary_method_slice": primary,
            "transitive_local_helper_slices": helpers, "request_contracts": requests,
            "literal_inertia_page_callsites": [render_callsite], "literal_inertia_page_callsite_count": 1,
            "shared_source_review_packet_sha256": packet["source_review_packet_sha256"],
            "external_dependency_semantics_complete": False, "route_ownership_credit": False,
            "controller_action_bridge_credit": False, "page_ownership_credit": False,
        },
        "frontend_static_path_contexts": static_path_contexts,
        "feature_identity_projection": feature_projection(matrix_by_id[FEATURE_ID]),
        "collision_checks": {
            "previous_review_source_collision": False, "current_owner_source_collision": False,
            "existing_controller_action_bridge_collision": False, "existing_page_owner_context_present": True,
            "existing_page_owner_inheritance_authorized": False,
        },
        "fresh_review_state": {
            "status": "PENDING", "allowed_outcomes": ["OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT", "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP"],
            "route_ownership_credit": False, "controller_action_bridge_credit": False,
            "page_ownership_credit": False, "correctness_credit": False, "downstream_credit": False,
        },
        "evidence_digests": {
            "queue_record_sha256": queue_row["queue_record_sha256"],
            "route_manifest_record_sha256": canonical_json_sha256(route_row),
            "route_candidate_record_sha256": canonical_json_sha256(candidate),
            "route_decision_sha256": canonical_json_sha256(decision),
            "primary_method_slice_sha256": primary["review_slice"]["text_sha256"],
            "local_support_sha256": canonical_json_sha256(helpers),
            "request_support_sha256": canonical_json_sha256(requests),
            "render_callsite_sha256": canonical_json_sha256(render_callsite),
            "frontend_static_path_context_sha256": canonical_json_sha256(static_path_contexts),
            "source_review_packet_sha256": packet["source_review_packet_sha256"],
        },
    }
    record["candidate_record_sha256"] = canonical_json_sha256(record)

    identity = {
        "candidate_id_list_sha256": canonical_list_sha256([record["candidate_id"]]),
        "queue_index_list_sha256": canonical_list_sha256([str(QUEUE_INDEX)]),
        "queue_id_list_sha256": canonical_list_sha256([QUEUE_ID]),
        "canonical_key_list_sha256": canonical_list_sha256([record["queue_canonical_key"]]),
        "route_record_id_list_sha256": canonical_list_sha256([ROUTE_ID]),
        "literal_route_name_list_sha256": canonical_list_sha256(["invoices.index"]),
        "action_key_list_sha256": canonical_list_sha256([action_key]),
        "candidate_record_sha256_list_sha256": canonical_list_sha256([record["candidate_record_sha256"]]),
        "records_sha256": canonical_json_sha256([record]),
        "source_review_packet_sha256": packet["source_review_packet_sha256"],
        "page_context_sha256": canonical_json_sha256(page_context),
        "next_queue_record_sha256": next_row["queue_record_sha256"],
    }

    return {
        "schema_version": "run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22-v1",
        "run_id": "RUN-137-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-COHORT-WAVE-22",
        "status": "ONE_NAME_ONLY_FINANCE_INVOICE_INDEX_ROUTE_ACTION_CANDIDATE_PENDING_FRESH_REVIEW_ZERO_CREDIT",
        "generated_on": "2026-08-26",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT, "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE, "routes_tree": ROUTES_TREE, "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE, "tests_tree": TESTS_TREE,
            "prompt_path": str(PROMPT_PATH), "prompt_sha256": PROMPT_SHA256,
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest for name, digest in EXPECTED_INPUT_SHA256.items()},
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. organization_id is legacy storage "
            "or organisational context, not a SaaS tenant boundary. Exact permission, approved-Site reach, canonical "
            "record ownership, privacy, and direct-object rules remain separate and receive zero credit here."
        ),
        "selection_contract": {
            "outcome_neutral": True, "candidate_owner_projection_authorized": False,
            "rule": "Select all and only zero-based RUN-090 queue index 77 after the committed RUN-135/RUN-136 boundary; require exact queue, route, name-only feature, unique controller-action, no prior review, no current owner, and no bridge collision identity.",
            "name_only_rule": "The exact invoices.index matrix token supplies the sole feature candidate. The backend lane supplies zero feature candidates; unique InvoiceController::index resolution is review context and does not strengthen feature identity.",
            "page_rule": "The Index and Show page records are existing static owners. The selected render call and all static path contexts are non-inheritable; RUN-137 contains zero page candidates.",
            "adjacent_route_rule": "Every other invoice action and every queue row from index 78 onward is outside RUN-137.",
            "prohibited_inheritance": ["route group, adjacency, or queue proximity", "existing Index or Show page ownership", "controller render or frontend path presence", "permission middleware or policy registration", "legacy organization storage context", "unexecuted tests, runtime, browser, benchmark, Pass, final-finding, or completion"],
        },
        "current_baseline": expected_baseline,
        "source_review_packet": packet,
        "current_page_owner_context_non_inheritable": page_context,
        "next_queue_boundary": {"queue_index_zero_based": 78, "queue_id": next_row["queue_id"], "route_record_id": next_row["source_record_id"], "candidate_feature_id": next_row["candidate_feature_id"], "selected_for_run137": False, "credit_awarded": False},
        "page_and_caller_context_boundary": {
            "selected_controller_literal_inertia_page_callsites": 1, "existing_page_owner_context_rows": 2,
            "existing_selected_render_page_owner_rows": 1, "frontend_static_path_contexts": len(static_path_contexts),
            "new_page_owner_records": 0, "page_ownership_inherited": False, "page_ownership_reassigned": False,
            "render_or_caller_presence_preselects_route_outcome": False,
        },
        "semantic_review_focus": [
            "trace invoice list, filters, eager-loaded lines, summary, balance projection, and manager-only reference data",
            "separate the invoice list and aggregates from the separately Site-scoped Client picker",
            "trace finance.ar.view and finance.ar.manage without treating permission presence as Site or privacy proof",
            "review allocation type, Site, integrity, and canonical invoice binding in the amount projection",
            "preserve existing page ownership and treat all render/path callers as context only",
            "expand the packet when any excluded dependency is outcome-material",
        ],
        "risk_register": [
            {"risk_id": "RUN137-RISK-INVOICE-LIST-SITE-SCOPE", "observed_loci": ["app/Domain/Finance/Http/Controllers/InvoiceController.php:39-44", "app/Domain/Finance/Http/Controllers/InvoiceController.php:98-105", "app/Domain/Finance/Http/Controllers/InvoiceController.php:116-123"], "observation": "The selected invoice rows and summary are frozen with organization storage scope, while only the manager Client picker visibly invokes approved-Site Client scope. Fresh review must determine Site and privacy semantics.", "credit_authorized": False},
            {"risk_id": "RUN137-RISK-ALLOCATION-PROJECTION", "observed_loci": ["app/Domain/Finance/Http/Controllers/InvoiceController.php:82-96", "app/Domain/Finance/Models/FinPaymentAllocation.php:17-110"], "observation": "Outstanding amounts aggregate allocation records by morph type and selected IDs. Fresh review must determine type, Site, integrity, and canonical binding sufficiency.", "credit_authorized": False},
            {"risk_id": "RUN137-RISK-PERMISSION-NOT-SITE-PROOF", "observed_loci": ["routes/finance.php:675-677", "app/Domain/Finance/Policies/FinInvoicePolicy.php:8-20"], "observation": "The route and policy expose AR permission context; neither is pre-credited as approved-Site or privacy correctness.", "credit_authorized": False},
            {"risk_id": "RUN137-RISK-PAGE-CALLER-INHERITANCE", "observed_loci": ["app/Domain/Finance/Http/Controllers/InvoiceController.php:109", "resources/js/pages/finance/invoices/Index.tsx:118-163"], "observation": "An existing page owner and static same-path callers do not establish route-action ownership or correctness.", "credit_authorized": False},
            {"risk_id": "RUN137-RISK-PACKET-EXPANSION", "observed_loci": packet["known_excluded_expansion_candidates"], "observation": "Known UI and allocation dependencies remain expansion candidates; packet completeness is deliberately not claimed.", "credit_authorized": False},
            {"risk_id": "RUN137-RISK-UNEXECUTED-TESTS", "observed_loci": ["tests/Feature/Finance/InvoiceIndexReceiptTest.php:1-96", "tests/Feature/Finance/CommercialFinanceSiteBoundaryTest.php:1-139", "tests/Browser/Finance/FinanceInvoicesTest.php:1-29"], "observation": "Frozen tests are static context only and were not executed for RUN-137.", "credit_authorized": False},
        ],
        "stop_rules": [
            "Abort on checkpoint, tree, input, source-file blob, queue identity, or source-record drift.",
            "Use EVIDENCE_GAP for any unresolved outcome-material dependency, authorization, Site, privacy, query, projection, page, caller, lifecycle, or test semantics.",
            "Do not integrate an owner until two fresh independent reviews and a fresh synthesis reconcile the one row.",
            "Do not add page ownership or any correctness, runtime, browser, benchmark, Pass, final-finding, or completion credit.",
        ],
        "counts": {
            "candidate_route_actions": 1, "candidate_route_records": 1, "candidate_controller_action_bridges": 1,
            "candidate_page_records": 0, "distinct_feature_ids": 1, "distinct_feature_ids_not_in_current_owner_set": 0,
            "distinct_feature_ids_not_in_current_route_owner_set": 1, "both_lanes_identical_candidates": 0,
            "name_only_candidates": 1, "controller_files": 1, "frontend_static_path_contexts": len(static_path_contexts),
            "selected_literal_controller_render_callsites": 1, "existing_page_owner_context_rows": 2,
            "required_source_files": packet["required_source_file_count"],
            "material_dependency_method_slices": packet["material_dependency_method_slice_count"],
            "known_excluded_expansion_candidates": len(packet["known_excluded_expansion_candidates"]),
            "new_feature_ids": 0, "queue_pending_before": 393, "selected_pending_queue_surfaces": 1,
            "queue_unselected_pending": 392, "selected_queue_surfaces_still_pending": 1,
            "current_reviewed_queue_surface_rows": 114, "current_pending_queue_surface_rows": 393,
            "ownership_credit_awarded": 0, "controller_action_bridge_credit_awarded": 0,
            "page_ownership_credit_awarded": 0, "site_authorization_credit_awarded": 0,
            "permission_credit_awarded": 0, "privacy_credit_awarded": 0, "query_correctness_credit_awarded": 0,
            "projection_correctness_credit_awarded": 0, "runtime_credit": 0, "application_browser_credit": 0,
            "executed_test_credit": 0, "benchmark_credit": 0, "pass_credit": 0, "final_finding_credit": 0,
            "completion_credit": 0, "new_queue_review_credit": 0, "matrix_mutation_credit": 0,
            "application_source_mutation_credit": 0, "release_credit": 0,
        },
        "identity": identity,
        "records": [record],
        "fresh_review_contract": {
            "status": "PENDING", "required_independent_candidate_reviews": 2, "required_cohort_synthesis": 1,
            "required_reviews": 3, "reviewers_must_be_fresh_from_discovery_producer": True,
            "cohort_synthesizer_must_be_fresh_from_both_candidate_reviewers": True,
            "required_reconciled_outcome_per_candidate": True,
            "allowed_outcomes": ["OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT", "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP"],
            "disagreement_rule": "Unresolved reviewer disagreement or material packet incompleteness stops integration and requires EVIDENCE_GAP or a bounded expansion and fresh review.",
            "page_owner_records_authorized": 0, "ownership_integration_authorized": False,
            "downstream_credit_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "outcome_variables": "O owner, S shared, A alias, D dead, E evidence gap",
            "equation": "O + S + A + D + E = 1",
            "bounded_sources": "3929 = (660 + O) + (3269 - O)",
            "owner_surfaces": "660 + O = (303 + O) routes + 357 pages",
            "current_queue": "507 = 114 reviewed + 393 pending; 393 pending = 1 frozen candidate + 392 other pending",
            "post_review_queue_projection_only": "507 = 115 reviewed + 392 pending",
            "post_review_outcome_projection_only": "115 = (92 + O) owner + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "post_review_without_ownership_projection_only": "415 - O = 392 pending + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "route_universe": "3218 = (303 + O) owner + (12 + S) shared + (5 + A) alias + D dead + (2898 - O - S - A - D) residual; 7 + E gaps are tagged within residual",
            "pages": "711 = 357 owner + 9 shared + 345 residual; one earlier gap remains tagged within residual",
            "controller_action_bridges": "91 + O",
            "feature_sets": "256 = 234 H + 22 D; route 62 + O, page 242, overlap 48 + O because the selected feature currently has page but no route ownership",
            "matrix_mutation": "0 rows and 0 cells changed",
            "bounded_ownership_percent": "100 * (660 + O) / 3929; no projection is current credit",
            "all_owner_projection_only": {"source_owner_records": 661, "route_owner_records": 304, "page_owner_records": 357, "static_controller_action_bridges": 92, "route_distinct_feature_ids": 63, "route_page_feature_overlap": 49, "bounded_static_source_residual_records": 3268, "residual_explicit_unmapped_routes": 2897, "bounded_static_source_ownership_percent": "16.823619", "reviewed_queue_surface_rows": 115, "owner_queue_surface_rows": 93, "pending_unreviewed_queue_surface_rows": 392, "queue_surfaces_without_ownership": 414, "projection_credit_awarded": False},
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {"run_077_bounded_static_records": 3929, "framework_expanded_route_page_denominator": None, "complete_route_page_feature_crosswalk": False, "gate_4_complete": False},
        "credit_boundary": {
            "route_action_candidate_cohort": True, "static_route_feature_ownership": False,
            "static_controller_action_bridge": False, "page_ownership": False, "new_queue_review": False,
            "navigation": False, "canonical_object_ownership": False, "matrix_mutation": False,
            "application_source_mutation": False, "responsive_application": False, "visual_application_workflow": False,
            "release": False, "prior_page_owner_context_preserved": True, "prior_page_owner_context_inherited": False,
            "framework_route_reachability": False, "site_authorization_correctness": False,
            "permission_correctness": False, "direct_object_concealment": False, "privacy_correctness": False,
            "query_correctness": False, "projection_correctness": False, "lifecycle_correctness": False,
            "concurrency_correctness": False, "runtime": False, "database": False, "build": False,
            "application_browser": False, "executed_tests": False, "benchmark": False, "ease": False,
            "pass": False, "final_finding": False, "completion": False, "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"], "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": sha256_file(OUTPUT_PATH), "candidate_route_actions": payload["counts"]["candidate_route_actions"],
        "queue_id": payload["records"][0]["queue_id"], "route_record_id": payload["records"][0]["route_source"]["route_record_id"],
        "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
        "completion_credit": payload["counts"]["completion_credit"],
    }, indent=2))


if __name__ == "__main__":
    main()
