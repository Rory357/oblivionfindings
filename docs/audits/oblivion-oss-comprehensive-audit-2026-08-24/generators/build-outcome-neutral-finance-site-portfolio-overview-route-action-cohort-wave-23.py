#!/usr/bin/env python3
"""Freeze RUN-141's finance Site-portfolio JSON action without an outcome.

The committed RUN-139/RUN-140 boundary leaves 392 direct-exact queue rows
pending. This producer selects zero-based queue index 78 only. The duplicate
local route-name literal, existing page owner, page-path callers, adjacent
already-reviewed row, next pending row, and unexecuted tests are context only.
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
    / "generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py"
)
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

CHECKPOINT_COMMIT = "61d544240837bdceabd126de1595729927db2177"
CHECKPOINT_TREE = "8b64cacdcb88c9141cc068943e8628694da43d28"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

FEATURE_ID = "CAP-FIN-SITE-PORTFOLIO-OVERVIEW"
QUEUE_INDEX = 78
QUEUE_ID = "RUN090-ROUTE-0079"
ROUTE_ID = "RUN077-ROUTE-0669"
SIBLING_QUEUE_INDEX = 40
SIBLING_QUEUE_ID = "RUN090-ROUTE-0041"
SIBLING_ROUTE_ID = "RUN077-ROUTE-0418"
EXCLUDED_ADJACENT_INDEX = 79
EXCLUDED_ADJACENT_QUEUE_ID = "RUN090-ROUTE-0080"
EXCLUDED_ADJACENT_ROUTE_ID = "RUN077-ROUTE-0688"
NEXT_PENDING_INDEX = 80
NEXT_PENDING_QUEUE_ID = "RUN090-ROUTE-0081"
NEXT_PENDING_ROUTE_ID = "RUN077-ROUTE-0689"
PAGE_ID = "PAGE-ROOT-FC2C5F5706FD9066"

spec = importlib.util.spec_from_file_location("run137_template", TEMPLATE_GENERATOR)
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
    "matrix": BASE.INPUT_PATHS["matrix"],
    "task_contract": AUDIT_DIR / "task-scripts/cap-fin-site-portfolio-overview.md",
    "manifest": BASE.INPUT_PATHS["manifest"],
    "classification": BASE.INPUT_PATHS["classification"],
    "candidate_manifest": BASE.INPUT_PATHS["candidate_manifest"],
    "candidate_review": BASE.INPUT_PATHS["candidate_review"],
    "ownership_ledger": BASE.INPUT_PATHS["ownership_ledger"],
    "direct_queue_generator": BASE.INPUT_PATHS["direct_queue_generator"],
    "direct_queue": BASE.INPUT_PATHS["direct_queue"],
    "run133_cohort": BASE.INPUT_PATHS["run133_cohort"],
    "run134_overlay": BASE.INPUT_PATHS["run134_overlay"],
    "run137_cohort": AUDIT_DIR / "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json",
    "run138_overlay": AUDIT_DIR / "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json",
    "run139_reporting": AUDIT_DIR / "evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json",
    "run140_dashboard_html": AUDIT_DIR / "audit-dashboard.html",
    "run140_dashboard_receipt": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json",
}
for input_name in (*BASE.BASE.COHORT_NAMES, *BASE.BASE.OVERLAY_NAMES):
    INPUT_PATHS[input_name] = BASE.BASE.INPUT_PATHS[input_name]

EXPECTED_INPUT_SHA256 = {
    "template_generator": "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9",
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "task_contract": "4e928479e6cbac4bfaa08ac9a8619df0ae741ebfead4770ca77c803cdd96f8a8",
    "manifest": BASE.EXPECTED_INPUT_SHA256["manifest"],
    "classification": BASE.EXPECTED_INPUT_SHA256["classification"],
    "candidate_manifest": BASE.EXPECTED_INPUT_SHA256["candidate_manifest"],
    "candidate_review": BASE.EXPECTED_INPUT_SHA256["candidate_review"],
    "ownership_ledger": BASE.EXPECTED_INPUT_SHA256["ownership_ledger"],
    "direct_queue_generator": BASE.EXPECTED_INPUT_SHA256["direct_queue_generator"],
    "direct_queue": BASE.EXPECTED_INPUT_SHA256["direct_queue"],
    "run133_cohort": "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4",
    "run134_overlay": "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669",
    "run137_cohort": "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc",
    "run138_overlay": "005a55c952ec3f3b2a5bac9f3c99000fa4eae65a488764dfd1f4662063431701",
    "run139_reporting": "bdc0b866db9409220bcac7bf66075e8cf89460fb40d61021fe7c98a705597231",
    "run140_dashboard_html": "f9f11d0ac7d70ab5829ce0e41435b185279beb5141afe701c7a578fdce59399d",
    "run140_dashboard_receipt": "1cae6bb23a9ede9bcda9cd975de07476516eeb18d6746f1aacf2653ecfe0c74f",
}
for input_name in (*BASE.BASE.COHORT_NAMES, *BASE.BASE.OVERLAY_NAMES):
    EXPECTED_INPUT_SHA256[input_name] = BASE.BASE.EXPECTED_INPUT_SHA256[input_name]

SOURCE_FILE_PURPOSES = {
    "routes/web.php": ("routes/web.php:369", "finance route loader"),
    "routes/finance.php": (
        "routes/finance.php:62; routes/finance.php:93-96; routes/finance.php:776-782",
        "outer and nested groups, separate page route, selected JSON action, and local-name collision",
    ),
    "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php": (
        "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:1-206",
        "complete selected JSON controller and period helper",
    ),
    "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php": (
        "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:14-154",
        "separate page-route controller and literal render sibling context",
    ),
    "app/Domain/Finance/Services/FinancialInsightsScopeResolver.php": (
        "app/Domain/Finance/Services/FinancialInsightsScopeResolver.php:25-70",
        "aggregate approved-Site and exact global permission boundary",
    ),
    "app/Domain/Finance/Services/FinancialInsightsScopeDecision.php": (
        "app/Domain/Finance/Services/FinancialInsightsScopeDecision.php:12-70",
        "immutable aggregate decision context",
    ),
    "app/Domain/Finance/Services/FinancialInsightsScope.php": (
        "app/Domain/Finance/Services/FinancialInsightsScope.php:1-11",
        "decision enum context",
    ),
    "app/Domain/Finance/Services/SiteFinancialDashboardService.php": (
        "app/Domain/Finance/Services/SiteFinancialDashboardService.php:88-134",
        "selected multi-Site JSON projection",
    ),
    "app/Domain/Finance/Services/SiteCostService.php": (
        "app/Domain/Finance/Services/SiteCostService.php:50-85",
        "per-Site cost breakdown dependency",
    ),
    "app/Domain/Finance/Services/CostPerResidentService.php": (
        "app/Domain/Finance/Services/CostPerResidentService.php:43-74",
        "per-resident projection dependency",
    ),
    "app/Domain/Finance/Services/StaffingCostService.php": (
        "app/Domain/Finance/Services/StaffingCostService.php:81-106",
        "batched staffing comparison dependency",
    ),
    "app/Services/UserSiteAccessService.php": (
        "app/Services/UserSiteAccessService.php:70-155",
        "canonical accessible-Site and explicit bypass derivation",
    ),
    "app/Models/User.php": ("app/Models/User.php:359-415", "exact capability evaluation"),
    "app/Models/Site.php": ("app/Models/Site.php:378-392", "active and non-archived Site scopes"),
    "app/Models/Client.php": (
        "app/Models/Client.php:1-430",
        "aggregate resolver Client-relationship query context",
    ),
    "bootstrap/app.php": ("bootstrap/app.php:1-96", "permission middleware alias registration"),
    "app/Http/Middleware/EnsurePermission.php": (
        "app/Http/Middleware/EnsurePermission.php:1-29",
        "literal finance.dashboard enforcement path",
    ),
    "database/seeders/RbacSeeder.php": (
        "database/seeders/RbacSeeder.php:1-900",
        "finance dashboard and view-all-Sites permission assignment context",
    ),
    "resources/js/pages/finance/sites-overview/Show.tsx": (
        "resources/js/pages/finance/sites-overview/Show.tsx:1-571",
        "existing page owner and same-page route caller context",
    ),
    "resources/js/components/finance/overview-hub.tsx": (
        "resources/js/components/finance/overview-hub.tsx:1-80",
        "separate page-path navigation context",
    ),
    "resources/js/pages/sites/_ledger-panel.tsx": (
        "resources/js/pages/sites/_ledger-panel.tsx:820-840",
        "separate page-path cross-module caller context",
    ),
    "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php": (
        "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:98-115; tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:280-390; tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:493-531",
        "unexecuted selected API scope, projection, and global-permission assertions",
    ),
    "tests/Feature/Finance/SitesFinancialOverviewTest.php": (
        "tests/Feature/Finance/SitesFinancialOverviewTest.php:1-164",
        "unexecuted separate page-route and page-projection assertions",
    ),
    "docs/architecture/financial-insights-object-scope.md": (
        "docs/architecture/financial-insights-object-scope.md:1-41",
        "declared financial-insights object boundary",
    ),
    "docs/architecture/single-tenant-application.md": (
        "docs/architecture/single-tenant-application.md:1-21",
        "canonical one-organisation multi-Site boundary",
    ),
}

DEPENDENCY_METHOD_SPECS = [
    ("app/Domain/Finance/Services/FinancialInsightsScopeResolver.php", "resolveAggregate"),
    ("app/Services/UserSiteAccessService.php", "accessibleSiteIds"),
    ("app/Services/UserSiteAccessService.php", "canBypass"),
    ("app/Models/User.php", "canDo"),
    ("app/Domain/Finance/Services/FinancialInsightsScopeDecision.php", "denied"),
    ("app/Domain/Finance/Services/FinancialInsightsScopeDecision.php", "global"),
    ("app/Domain/Finance/Services/FinancialInsightsScopeDecision.php", "accessibleSites"),
    ("app/Domain/Finance/Services/FinancialInsightsScopeDecision.php", "isDenied"),
    ("app/Domain/Finance/Services/SiteFinancialDashboardService.php", "getSiteSummaries"),
    ("app/Domain/Finance/Services/SiteCostService.php", "breakdown"),
    ("app/Domain/Finance/Services/CostPerResidentService.php", "calculate"),
    ("app/Domain/Finance/Services/StaffingCostService.php", "perSiteComparison"),
    ("app/Models/Site.php", "scopeActive"),
    ("app/Models/Site.php", "scopeNotArchived"),
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
            "app/Domain/Finance/Models/FinCostAllocation.php",
            "app/Domain/Finance/Models/FinJournal.php",
            "app/Domain/Finance/Models/FinJournalLine.php",
            "app/Domain/Hr/Models/HrEmployeeProfile.php",
            "database/migrations/*financial-insights-dependency-schema*",
            "tests/Browser/Finance/*financial-insights-or-sites-overview*",
        ],
        "source_review_complete": False,
        "source_packet_completeness_claimed": False,
        "material_dependency_semantics_complete": False,
        "known_expansion_candidates_adjudicated": False,
        "unexecuted_test_context_is_runtime_evidence": False,
        "review_rule": (
            "Review the selected JSON action and every frozen material dependency. Expand the packet for every "
            "outcome-material Site, permission, privacy, query, projection, page, caller, period, or test dependency. "
            "Unresolved identity or semantics require EVIDENCE_GAP; this producer chooses no outcome."
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
    run139 = load_json(INPUT_PATHS["run139_reporting"])
    run140 = load_json(INPUT_PATHS["run140_dashboard_receipt"])
    cohorts = [load_json(INPUT_PATHS[name]) for name in BASE.BASE.COHORT_NAMES]
    cohorts += [load_json(INPUT_PATHS["run133_cohort"]), load_json(INPUT_PATHS["run137_cohort"])]
    overlay_names = (*BASE.BASE.OVERLAY_NAMES, "run134_overlay", "run138_overlay")
    overlays = [(name, load_json(INPUT_PATHS[name])) for name in overlay_names]

    assert candidate_review["verdict"]["decision"] == "GO"
    assert run140["verification"]["state"] == "GO"
    assert run140["credit_boundary"]["application_browser"] is False
    expected_baseline = {
        "source_owner_records": 661,
        "route_owner_records": 304,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 63,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 49,
        "static_controller_action_bridges": 92,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.823619",
        "bounded_static_source_residual_records": 3268,
        "residual_explicit_unmapped_routes": 2897,
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
        "reviewed_queue_surface_rows": 115,
        "owner_queue_surface_rows": 93,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 392,
        "queue_surfaces_without_ownership": 414,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
    }
    for key, value in expected_baseline.items():
        assert run139["counts"][key] == value, key

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_by_id = index_unique(route_rows, "route_record_id")
    decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    candidate_by_id = index_unique(candidates["route_static_candidate_census"]["records"], "route_record_id")

    owner_rows = list(ownership["records"])
    owner_origin = {row["source_record_id"]: "ownership_ledger" for row in owner_rows}
    bridge_rows: list[dict[str, Any]] = []
    bridge_ids: set[str] = set()
    for origin, overlay in overlays:
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
    assert len(owner_rows) == 661
    assert Counter(row["surface"] for row in owner_rows) == {
        "ROUTE_SOURCE_RECORD": 304,
        "PAGE_ROOT_SOURCE_RECORD": 357,
    }
    assert len(bridge_rows) == 92
    assert ROUTE_ID not in owner_by_id and SIBLING_ROUTE_ID not in owner_by_id
    assert PAGE_ID in owner_by_id and owner_origin[PAGE_ID] == "ownership_ledger"
    assert owner_by_id[PAGE_ID]["feature_id"] == FEATURE_ID
    assert FEATURE_ID not in {row["feature_id"] for row in owner_rows if row["surface"] == "ROUTE_SOURCE_RECORD"}

    reviewed_route_ids: set[str] = set()
    for cohort in cohorts:
        reviewed_route_ids |= cohort_route_ids(cohort)
    assert len(reviewed_route_ids) == 113
    assert ROUTE_ID not in reviewed_route_ids and SIBLING_ROUTE_ID not in reviewed_route_ids
    assert EXCLUDED_ADJACENT_ROUTE_ID in reviewed_route_ids
    assert NEXT_PENDING_ROUTE_ID not in reviewed_route_ids
    assert run139["verified_noninheritance_boundary"]["next_queue_index_zero_based"] == QUEUE_INDEX
    assert run139["verified_noninheritance_boundary"]["next_queue_id"] == QUEUE_ID
    assert run139["verified_noninheritance_boundary"]["next_route_record_id"] == ROUTE_ID
    assert run139["verified_noninheritance_boundary"]["next_boundary_selected_or_credited"] is False

    queue_row = queue["records"][QUEUE_INDEX]
    sibling_queue_row = queue["records"][SIBLING_QUEUE_INDEX]
    excluded_adjacent = queue["records"][EXCLUDED_ADJACENT_INDEX]
    next_pending = queue["records"][NEXT_PENDING_INDEX]
    assert (queue_row["queue_id"], queue_row["source_record_id"], queue_row["candidate_feature_id"]) == (
        QUEUE_ID,
        ROUTE_ID,
        FEATURE_ID,
    )
    assert queue_row["queue_record_sha256"] == "c68159a2825572283d2c312c792910943993ca864aa427d28c1cf32ad75c808a"
    assert (sibling_queue_row["queue_id"], sibling_queue_row["source_record_id"], sibling_queue_row["candidate_feature_id"]) == (
        SIBLING_QUEUE_ID,
        SIBLING_ROUTE_ID,
        FEATURE_ID,
    )
    assert (excluded_adjacent["queue_id"], excluded_adjacent["source_record_id"], excluded_adjacent["queue_record_sha256"]) == (
        EXCLUDED_ADJACENT_QUEUE_ID,
        EXCLUDED_ADJACENT_ROUTE_ID,
        "211f64b0be95130eb5aef8d196032b161102565f34c1568d1adbe0f78742b151",
    )
    assert (next_pending["queue_id"], next_pending["source_record_id"], next_pending["queue_record_sha256"]) == (
        NEXT_PENDING_QUEUE_ID,
        NEXT_PENDING_ROUTE_ID,
        "b73b6a2cf4340520554c6725d701e26f1b313334e8025d6db4f5e7de51392fda",
    )
    excluded_owner = owner_by_id[EXCLUDED_ADJACENT_ROUTE_ID]
    assert owner_origin[EXCLUDED_ADJACENT_ROUTE_ID] == "run098_overlay"
    assert excluded_owner["review_outcome"] == "OWNER_ROUTE_ACTION"
    excluded_bridges = [row for row in bridge_rows if row.get("route_record_id") == EXCLUDED_ADJACENT_ROUTE_ID]
    assert len(excluded_bridges) == 1 and excluded_bridges[0]["static_controller_action_bridge_credit"] is True

    route_row = route_by_id[ROUTE_ID]
    decision = decision_by_id[ROUTE_ID]
    candidate = candidate_by_id[ROUTE_ID]
    resolution = candidate["backend_method_relation"]["resolution"]
    sibling_route = route_by_id[SIBLING_ROUTE_ID]
    sibling_candidate = candidate_by_id[SIBLING_ROUTE_ID]
    sibling_resolution = sibling_candidate["backend_method_relation"]["resolution"]
    assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert queue_row["secondary_lane"]["relation_comparison"] == "NAME_ONLY"
    assert decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
    assert candidate["relation_comparison"] == "NAME_ONLY"
    assert candidate["name_relation"]["candidate_feature_ids"] == [FEATURE_ID]
    assert candidate["backend_method_relation"]["candidate_count"] == 0
    assert resolution["controller_file"] == "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php"
    assert resolution["method"] == "sitesOverview" and resolution["definition_line"] == 58
    assert sibling_candidate["relation_comparison"] == "BOTH_LANES_IDENTICAL"
    assert sibling_resolution["controller_file"] == "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php"
    assert sibling_resolution["method"] == "index" and sibling_resolution["definition_line"] == 22
    assert route_row["inline_literal_route_names"] == sibling_route["inline_literal_route_names"] == ["sites.overview"]

    selected_bridge_key = (resolution["controller_file"], "sitesOverview", FEATURE_ID)
    assert selected_bridge_key not in {
        (row["controller_file"], row["method"], row["feature_id"]) for row in bridge_rows
    }

    primary = semantic_slice(resolution["controller_file"], "sitesOverview")
    helpers = transitive_local_helper_slices(
        resolution["controller_file"], "sitesOverview", primary["review_slice"]["text"]
    )
    assert [row["method"] for row in helpers] == ["parsePeriod"]
    requests = request_contracts_for_slice(resolution["controller_file"], primary["review_slice"]["text"])
    assert requests == []
    sibling_primary = semantic_slice(sibling_resolution["controller_file"], "index")
    render_callsite = {
        **exact_source_line(
            sibling_resolution["controller_file"],
            "return Inertia::render('finance/sites-overview/Show', [",
        ),
        "render_name": "finance/sites-overview/Show",
        "existing_page_record_id": PAGE_ID,
        "existing_page_feature_id": FEATURE_ID,
        "selected_api_action_render_callsite": False,
        "page_owner_inheritance_authorized": False,
    }
    caller_contexts = [
        exact_source_line("resources/js/pages/finance/sites-overview/Show.tsx", "'/finance/sites',"),
        exact_source_line("resources/js/components/finance/overview-hub.tsx", "href: '/finance/sites',"),
        exact_source_line("resources/js/pages/sites/_ledger-panel.tsx", '<Link href="/finance/sites">'),
    ]
    for row in caller_contexts:
        row["targets_separate_page_route_only"] = True
        row["selected_api_route_or_page_ownership_inheritable"] = False
    api_frontend_occurrences: list[str] = []
    tracked_frontend_sources = [
        relative
        for relative in git("ls-files", "--", "resources/js").splitlines()
        if Path(relative).suffix.lower() in {".js", ".jsx", ".ts", ".tsx"}
    ]
    for relative in tracked_frontend_sources:
        text = (REPO / relative).read_text(encoding="utf-8-sig")
        if "/finance/api/sites/overview" in text or "finance.api.sites.overview" in text:
            api_frontend_occurrences.append(relative)
    assert api_frontend_occurrences == []

    page_owner = owner_by_id[PAGE_ID]
    page_context = {
        "existing_page_owner_record": {
            "source_record_id": PAGE_ID,
            "source_record_key": page_owner["source_record_key"],
            "owner_row_id": page_owner["mapping_id"],
            "owner_row_sha256": page_owner["ledger_row_sha256"],
            "feature_id": page_owner["feature_id"],
            "page_file": page_owner["page_source"]["page_file"],
            "page_file_sha256": page_owner["page_source"]["page_file_sha256"],
            "current_static_page_owner_credit_preserved": True,
            "ownership_inheritable_to_run141": False,
            "route_or_correctness_credit_inheritable_to_run141": False,
        },
        "separate_page_route_sibling": {
            "queue_index_zero_based": SIBLING_QUEUE_INDEX,
            "queue_id": SIBLING_QUEUE_ID,
            "route_record_id": SIBLING_ROUTE_ID,
            "local_literal_route_name": "sites.overview",
            "effective_route_name": "finance.sites.overview",
            "effective_uri": "/finance/sites",
            "action_expression": sibling_route["action_expression"],
            "relation_comparison": sibling_candidate["relation_comparison"],
            "current_review_state": sibling_queue_row["review_state"]["status"],
            "selected_or_credited_by_run141": False,
            "identity_or_outcome_inheritable_to_selected_api_action": False,
            "controller_action_slice": sibling_primary,
            "literal_render_callsite": render_callsite,
        },
        "page_path_caller_contexts": caller_contexts,
        "page_path_caller_context_count": len(caller_contexts),
        "selected_api_frontend_exact_caller_occurrences": api_frontend_occurrences,
        "selected_api_frontend_exact_caller_occurrence_count": 0,
        "selected_api_frontend_caller_scan_denominator": "Git-tracked resources/js JavaScript and TypeScript source",
        "ignored_generated_wayfinder_bindings_are_consumer_callers": False,
        "api_frontend_caller_absence_is_not_dead_or_noncanonical_proof": True,
        "new_page_owner_records": 0,
        "page_ownership_inherited": False,
        "page_ownership_reassigned": False,
        "caller_presence_preselects_selected_api_outcome": False,
    }
    page_context["page_context_sha256"] = canonical_json_sha256(page_context)

    packet = source_review_packet()
    assurance_questions = [
        {
            "question_id": "RUN141-Q-IDENTITY-COLLISION",
            "category": "feature_identity",
            "loci": ["routes/finance.php:93-96", "routes/finance.php:776-782"],
            "question": "Does the JSON action implement the same Site-portfolio user job, a shared API relation, an alias, a noncanonical surface, or an evidence gap despite the duplicate unprefixed name token?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-EFFECTIVE-NAME-REACHABILITY",
            "category": "route_registration",
            "loci": ["routes/finance.php:62", "routes/finance.php:778-782"],
            "question": "Does framework registration confirm finance.api.sites.overview independently of finance.sites.overview without using the shared local literal as inherited identity?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-SITE-SCOPE",
            "category": "approved_site_privacy",
            "loci": ["app/Domain/Finance/Services/FinancialInsightsScopeResolver.php:35-70", "app/Domain/Finance/Services/SiteFinancialDashboardService.php:88-134"],
            "question": "Do ordinary, global, no-Site, inactive-Site, and archived-Site actors receive exactly the authorized Site set and no foreign Site names or amounts?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-SITE-TYPE-CONVERGENCE",
            "category": "query_projection_consistency",
            "loci": ["app/Domain/Finance/Services/SiteFinancialDashboardService.php:93-100", "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:42-49"],
            "question": "Is the API exclusion of residential Sites, while the page route includes them, intentional and consistent with the canonical portfolio?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-PERIOD-CONTRACT",
            "category": "input_and_period_correctness",
            "loci": ["app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:58-66", "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:194-205", "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:22-38"],
            "question": "Are invalid, inverted, date-only, timezone, default, and end-of-day period semantics validated and consistent between the selected API and separate page route?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-COST-PROVENANCE",
            "category": "financial_projection",
            "loci": ["app/Domain/Finance/Services/SiteFinancialDashboardService.php:102-128", "app/Domain/Finance/Services/SiteCostService.php:50-85", "app/Domain/Finance/Services/CostPerResidentService.php:43-74", "app/Domain/Finance/Services/StaffingCostService.php:81-106"],
            "question": "Do allocation provenance and freshness plus cost, occupancy, resident, staffing, status, date, and Site predicates establish period-correct canonical summaries without stale, duplicate, or foreign data?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-CALLER-DISCOVERABILITY",
            "category": "caller_and_handoff",
            "loci": ["resources/js/pages/finance/sites-overview/Show.tsx:182", "resources/js/components/finance/overview-hub.tsx:50", "resources/js/pages/sites/_ledger-panel.tsx:831"],
            "question": "With zero exact frontend callers for the selected JSON endpoint, what authorized consumer, discoverability path, and workflow handoff establish its intended use?",
            "credit_authorized": False,
        },
        {
            "question_id": "RUN141-Q-EXECUTABLE-ASSURANCE",
            "category": "tests_and_runtime",
            "loci": ["tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:280-390", "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:493-531", "tests/Feature/Finance/SitesFinancialOverviewTest.php:1-164"],
            "question": "Do controlled executable tests cover the selected endpoint's authorization, Site privacy, projection, period errors, empty and error response states, global positive, and page/API divergence rather than only static expectations?",
            "credit_authorized": False,
        },
    ]
    assert len({row["question_id"] for row in assurance_questions}) == len(assurance_questions)
    assert len({row["question"] for row in assurance_questions}) == len(assurance_questions)

    action_key = f"{ROUTE_ID}|{resolution['controller_file']}:sitesOverview|{FEATURE_ID}"
    record: dict[str, Any] = {
        "candidate_id": "RUN141-FINANCE-SITE-PORTFOLIO-OVERVIEW-API-ROUTE-ACTION-01",
        "action_key": action_key,
        "run090_original_partition": queue_row["review_partition"],
        "queue_index_zero_based": QUEUE_INDEX,
        "queue_id": QUEUE_ID,
        "queue_canonical_key": queue_row["canonical_key"],
        "candidate_feature_id": FEATURE_ID,
        "name_only_identity": {
            "direct_identity": queue_row["direct_identity"],
            "relation_comparison": "NAME_ONLY",
            "name_candidate_count": 1,
            "name_candidate_feature_ids": [FEATURE_ID],
            "backend_candidate_count": 0,
            "backend_candidate_feature_ids": [],
            "backend_candidate_absence_is_not_negative_proof": True,
            "unique_controller_resolution_is_review_context_not_feature_identity": True,
            "candidate_only": True,
        },
        "route_name_collision_context": {
            "matrix_local_token": "sites.overview",
            "selected_local_literal": "sites.overview",
            "selected_effective_route_name": "finance.api.sites.overview",
            "selected_effective_uri": "/finance/api/sites/overview",
            "separate_page_local_literal": "sites.overview",
            "separate_page_effective_route_name": "finance.sites.overview",
            "separate_page_effective_uri": "/finance/sites",
            "same_local_literal_occurrences_in_frozen_route_file": 2,
            "collision_is_context_requiring_fresh_review": True,
            "collision_establishes_inherited_identity_or_outcome": False,
        },
        "route_source": {
            "route_record_id": ROUTE_ID,
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
            "static_group_context": {
                "outer_uri_prefix": "finance",
                "outer_name_prefix": "finance.",
                "nested_uri_prefix": "api",
                "nested_name_prefix": "api.",
                "derived_uri": "/finance/api/sites/overview",
                "derived_name": "finance.api.sites.overview",
                "framework_registration_executed": False,
            },
        },
        "controller_action": {
            "relation_class": "NAME_ONLY_EXACT_CONTROLLER_ACTION_REVIEW_CANDIDATE",
            "controller_fqcn": resolution["resolved_fqcn"],
            "primary_method_slice": primary,
            "transitive_local_helper_slices": helpers,
            "request_contracts": requests,
            "literal_inertia_page_callsites": [],
            "literal_inertia_page_callsite_count": 0,
            "returns_json_response": True,
            "shared_source_review_packet_sha256": packet["source_review_packet_sha256"],
            "external_dependency_semantics_complete": False,
            "route_ownership_credit": False,
            "controller_action_bridge_credit": False,
            "page_ownership_credit": False,
        },
        "feature_identity_projection": feature_projection(matrix_by_id[FEATURE_ID]),
        "page_sibling_and_caller_context_sha256": page_context["page_context_sha256"],
        "collision_checks": {
            "previous_review_source_collision": False,
            "current_owner_source_collision": False,
            "existing_controller_action_bridge_collision": False,
            "duplicate_local_route_name_context_present": True,
            "existing_page_owner_context_present": True,
            "existing_page_owner_inheritance_authorized": False,
            "sibling_route_identity_or_outcome_inheritance_authorized": False,
            "immediate_raw_neighbor_already_reviewed_and_recredit_prohibited": True,
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
            "selected_outcome": None,
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
            "source_review_packet_sha256": packet["source_review_packet_sha256"],
            "page_context_sha256": page_context["page_context_sha256"],
            "provisional_assurance_questions_sha256": canonical_json_sha256(assurance_questions),
        },
    }
    record["candidate_record_sha256"] = canonical_json_sha256(record)

    identity = {
        "candidate_id_list_sha256": canonical_list_sha256([record["candidate_id"]]),
        "queue_index_list_sha256": canonical_list_sha256([str(QUEUE_INDEX)]),
        "queue_id_list_sha256": canonical_list_sha256([QUEUE_ID]),
        "canonical_key_list_sha256": canonical_list_sha256([record["queue_canonical_key"]]),
        "route_record_id_list_sha256": canonical_list_sha256([ROUTE_ID]),
        "literal_route_name_list_sha256": canonical_list_sha256(["sites.overview"]),
        "effective_route_name_list_sha256": canonical_list_sha256(["finance.api.sites.overview"]),
        "action_key_list_sha256": canonical_list_sha256([action_key]),
        "candidate_record_sha256_list_sha256": canonical_list_sha256([record["candidate_record_sha256"]]),
        "records_sha256": canonical_json_sha256([record]),
        "source_review_packet_sha256": packet["source_review_packet_sha256"],
        "page_context_sha256": page_context["page_context_sha256"],
        "excluded_adjacent_queue_record_sha256": excluded_adjacent["queue_record_sha256"],
        "next_pending_queue_record_sha256": next_pending["queue_record_sha256"],
    }

    return {
        "schema_version": "run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23-v1",
        "run_id": "RUN-141-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-COHORT-WAVE-23",
        "status": "ONE_NAME_ONLY_FINANCE_SITE_PORTFOLIO_JSON_ROUTE_ACTION_CANDIDATE_PENDING_FRESH_REVIEW_ZERO_CREDIT",
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
            "Oblivion Findings serves one operating organisation across multiple Sites. Exact permission, approved-Site "
            "reach, canonical record ownership, privacy, and direct-object rules are the authorization boundaries; no "
            "tenant identity or tenant inheritance is introduced or credited."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "Select all and only zero-based RUN-090 queue index 78 after the committed RUN-139/RUN-140 boundary; "
                "require exact queue, route, name-only feature candidate, unique controller action, no prior review, no "
                "current owner, and no bridge collision identity."
            ),
            "name_only_rule": (
                "The exact local token sites.overview supplies the sole matrix candidate. The backend lane supplies zero "
                "feature candidates. Effective finance.api.sites.overview and finance.sites.overview names distinguish "
                "the selected JSON route from the separate page route; the collision is review context only."
            ),
            "page_and_sibling_rule": (
                "The existing page owner, separate page controller/route, and page-path callers are non-inheritable. "
                "RUN-141 contains zero page candidates and selects neither the sibling route nor any caller."
            ),
            "adjacent_boundary_rule": (
                "Raw index 79 is already a RUN-098 owner plus bridge and is excluded with recredit prohibited. Raw "
                "index 80 is the next actually pending row and is frozen unselected."
            ),
            "prohibited_inheritance": [
                "route group, local-name collision, adjacency, or queue proximity",
                "existing page ownership, sibling controller/render, or page-path callers",
                "permission middleware, declared architecture, or frozen source tests",
                "the already reviewed raw index-79 route owner and bridge",
                "runtime, browser, benchmark, ease, Pass, final finding, release, or completion",
            ],
        },
        "current_baseline": expected_baseline,
        "source_review_packet": packet,
        "page_sibling_and_caller_context_non_inheritable": page_context,
        "excluded_immediate_raw_neighbor": {
            "queue_index_zero_based": EXCLUDED_ADJACENT_INDEX,
            "queue_id": EXCLUDED_ADJACENT_QUEUE_ID,
            "route_record_id": EXCLUDED_ADJACENT_ROUTE_ID,
            "candidate_feature_id": excluded_adjacent["candidate_feature_id"],
            "reviewed_owner_origin": owner_origin[EXCLUDED_ADJACENT_ROUTE_ID],
            "reviewed_outcome": excluded_owner["review_outcome"],
            "existing_bridge_id": excluded_bridges[0]["bridge_id"],
            "already_reviewed": True,
            "selected_for_run141": False,
            "recredit_authorized": False,
        },
        "next_pending_boundary": {
            "queue_index_zero_based": NEXT_PENDING_INDEX,
            "queue_id": NEXT_PENDING_QUEUE_ID,
            "route_record_id": NEXT_PENDING_ROUTE_ID,
            "candidate_feature_id": next_pending["candidate_feature_id"],
            "review_state": next_pending["review_state"]["status"],
            "selected_for_run141": False,
            "credit_awarded": False,
        },
        "provisional_assurance_questions": assurance_questions,
        "provisional_assurance_question_count": len(assurance_questions),
        "semantic_review_focus": [
            "adjudicate selected JSON action identity without inheriting from the duplicate local route-name token",
            "trace exact base permission, separately permissioned global reach, approved Sites, and no-Site denial",
            "compare selected API and separate page Site-type and period semantics without conflating the surfaces",
            "trace cost, resident, occupancy, staffing, date, status, and Site predicates through all material queries",
            "establish the intended authorized consumer because the frozen frontend contains zero exact API callers",
            "expand the packet and fail closed when any outcome-material dependency remains unresolved",
        ],
        "stop_rules": [
            "Abort on checkpoint, tree, input, source-file blob, queue identity, owner, bridge, or boundary drift.",
            "Use EVIDENCE_GAP for unresolved identity, Site, permission, privacy, query, projection, period, page, caller, or test semantics.",
            "Do not integrate an outcome until two fresh independent reviews and a fresh synthesis reconcile the one row.",
            "Do not recredit raw index 79 or inherit the sibling route/page/caller context.",
            "Do not add correctness, runtime, application-browser, executed-test, benchmark, Pass, final-finding, release, or completion credit.",
        ],
        "counts": {
            "candidate_route_actions": 1,
            "candidate_route_records": 1,
            "candidate_controller_action_bridges": 1,
            "candidate_page_records": 0,
            "distinct_feature_ids": 1,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "distinct_feature_ids_not_in_current_route_owner_set": 1,
            "both_lanes_identical_candidates": 0,
            "name_only_candidates": 1,
            "controller_files": 1,
            "selected_literal_controller_render_callsites": 0,
            "existing_page_owner_context_rows": 1,
            "separate_page_route_sibling_context_rows": 1,
            "page_path_caller_contexts": len(caller_contexts),
            "selected_api_frontend_exact_caller_occurrences": 0,
            "required_source_files": packet["required_source_file_count"],
            "material_dependency_method_slices": packet["material_dependency_method_slice_count"],
            "known_excluded_expansion_candidates": len(packet["known_excluded_expansion_candidates"]),
            "provisional_assurance_questions": len(assurance_questions),
            "new_feature_ids": 0,
            "queue_pending_before": 392,
            "selected_pending_queue_surfaces": 1,
            "queue_unselected_pending": 391,
            "selected_queue_surfaces_still_pending": 1,
            "current_reviewed_queue_surface_rows": 115,
            "current_pending_queue_surface_rows": 392,
            "excluded_already_reviewed_adjacent_rows": 1,
            "next_pending_boundary_rows": 1,
            "selected_outcomes": 0,
            "ownership_credit_awarded": 0,
            "controller_action_bridge_credit_awarded": 0,
            "page_ownership_credit_awarded": 0,
            "site_authorization_credit_awarded": 0,
            "permission_credit_awarded": 0,
            "privacy_credit_awarded": 0,
            "query_correctness_credit_awarded": 0,
            "projection_correctness_credit_awarded": 0,
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
        "records": [record],
        "fresh_review_contract": {
            "status": "PENDING",
            "required_independent_candidate_reviews": 2,
            "required_cohort_synthesis": 1,
            "required_reviews": 3,
            "reviewers_must_be_fresh_from_discovery_producer": True,
            "cohort_synthesizer_must_be_fresh_from_both_candidate_reviewers": True,
            "required_reconciled_outcome_per_candidate": True,
            "allowed_outcomes": [
                "OWNER_ROUTE_ACTION",
                "SHARED_RELATION",
                "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL",
                "EVIDENCE_GAP",
            ],
            "selected_outcomes": [],
            "disagreement_rule": (
                "Unresolved reviewer disagreement, naming-collision ambiguity, or material packet incompleteness stops "
                "integration and requires EVIDENCE_GAP or bounded expansion plus fresh review."
            ),
            "page_owner_records_authorized": 0,
            "ownership_integration_authorized": False,
            "downstream_credit_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "outcome_variables": "O owner, S shared, A alias, D dead, E evidence gap",
            "equation": "O + S + A + D + E = 1",
            "bounded_sources": "3929 = (661 + O) + (3268 - O)",
            "owner_surfaces": "661 + O = (304 + O) routes + 357 pages",
            "current_queue": "507 = 115 reviewed + 392 pending; 392 pending = 1 frozen candidate + 391 other pending",
            "post_review_queue_projection_only": "507 = 116 reviewed + 391 pending",
            "post_review_outcome_projection_only": "116 = (93 + O) owner + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "post_review_without_ownership_projection_only": "414 - O = 391 pending + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "route_universe": "3218 = (304 + O) owner + (12 + S) shared + (5 + A) alias + D dead + (2897 - O - S - A - D) residual; 7 + E gaps are tagged within residual",
            "pages": "711 = 357 owner + 9 shared + 345 residual; one earlier gap remains tagged within residual",
            "controller_action_bridges": "92 + O",
            "feature_sets": "256 = 234 H + 22 D; route 63 + O, page 242, overlap 49 + O because the selected feature currently has page but no route ownership",
            "matrix_mutation": "0 rows and 0 cells changed",
            "bounded_ownership_percent": "100 * (661 + O) / 3929; no projection is current credit",
            "all_owner_projection_only": {
                "source_owner_records": 662,
                "route_owner_records": 305,
                "page_owner_records": 357,
                "static_controller_action_bridges": 93,
                "route_distinct_feature_ids": 64,
                "route_page_feature_overlap": 50,
                "bounded_static_source_residual_records": 3267,
                "residual_explicit_unmapped_routes": 2896,
                "bounded_static_source_ownership_percent": "16.849071",
                "reviewed_queue_surface_rows": 116,
                "owner_queue_surface_rows": 94,
                "pending_unreviewed_queue_surface_rows": 391,
                "queue_surfaces_without_ownership": 413,
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
            "new_queue_review": False,
            "navigation": False,
            "canonical_object_ownership": False,
            "matrix_mutation": False,
            "application_source_mutation": False,
            "responsive_application": False,
            "visual_application_workflow": False,
            "release": False,
            "prior_page_owner_context_preserved": True,
            "prior_page_owner_context_inherited": False,
            "sibling_route_context_inherited": False,
            "already_reviewed_adjacent_row_recredited": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "query_correctness": False,
            "projection_correctness": False,
            "period_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json",
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
        "candidate_route_actions": payload["counts"]["candidate_route_actions"],
        "queue_id": payload["records"][0]["queue_id"],
        "route_record_id": payload["records"][0]["route_source"]["route_record_id"],
        "selected_outcomes": payload["counts"]["selected_outcomes"],
        "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
        "completion_credit": payload["counts"]["completion_credit"],
    }, indent=2))


if __name__ == "__main__":
    main()
