#!/usr/bin/env python3
"""Materialize the independently reviewed RUN-141 Site-portfolio API action.

Two blinded reviewers independently selected one bounded static route-action
owner. A distinct synthesizer reconciled their records, a 24-file correctness-
only source expansion, and one disclosed out-of-range-locus correction. This
receipt authorizes only later bounded route-owner and controller-action bridge
integration. It awards no current overlay, page, correctness, runtime, browser,
test, benchmark, release, final-finding, or completion credit.
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
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json"
COHORT_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json"

CHECKPOINT_COMMIT = "a88a0e415e3e912fabe3ac84f2dbe8b4fbbcbfac"
CHECKPOINT_TREE = "5598c9b881c807ea5405c1ffbe1338952b14325a"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_SHA256 = "9062d90b961e496b0bf5ad48fc3f930a8161394fb8d2b9b88ad298807bd90fc3"
COHORT_GENERATOR_SHA256 = "d3cfd34687ba6c6a9b6afecfe9bfc02d2b700b15de881c1ef651877c486fd6a0"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
SOURCE_PACKET_SHA256 = "f26d6249445bfaa19a5b8ff193ecd74eefe90c606514ea1705c0181914a9271f"
REQUIRED_SOURCE_IDENTITY_SHA256 = "a885a0d06b70b24c60da3dfef820974feb0c306cc1a0f28ac9e8ff347d339c5d"
EXPANSION_UNION_MANIFEST_SHA256 = "6562676aec8f389bd05a8ed94631aa9b1bb0e60b4b45f0962e77cb7c70c47b29"
TRACKED_JS_TS_FILE_LIST_SHA256 = "d0dbdc5adc1d0a80120aeb6e44b032870a25980ee4eb88b912a0c7ecffc4725c"
FINANCE_BROWSER_FILE_LIST_SHA256 = "d79bd73269012816106b942f9fc5823fb5f693e3284f90d636157781886379fd"

FEATURE_ID = "CAP-FIN-SITE-PORTFOLIO-OVERVIEW"
CANDIDATE_ID = "RUN141-FINANCE-SITE-PORTFOLIO-OVERVIEW-API-ROUTE-ACTION-01"
CANDIDATE_RECORD_SHA256 = "1cf385e029fe71de3b032c38298e833afe39aacb5063ef1ecba8ef7141b8db1f"
OWNER_SOURCE_RECORD_KEY = "route|RUN077-ROUTE-0669|CAP-FIN-SITE-PORTFOLIO-OVERVIEW"
ACTION_KEY = "RUN077-ROUTE-0669|app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:sitesOverview|CAP-FIN-SITE-PORTFOLIO-OVERVIEW"
BRIDGE_KEY = [
    "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php",
    "sitesOverview",
    FEATURE_ID,
]

GENERATOR_RELATIVE = Path(__file__).relative_to(REPO).as_posix()
OUTPUT_RELATIVE = OUTPUT_PATH.relative_to(REPO).as_posix()
ALLOWED_DIRTY_PATHS = {GENERATOR_RELATIVE, OUTPUT_RELATIVE}


# Order is sealed: fifteen A+B requests followed by nine A-only requests.
EXPANSION_SPECS: tuple[dict[str, Any], ...] = (
    {"path": "app/Services/UserSiteAccessService.php", "sha256": "dda7c5d5ad50030bbf19fd1c157cd44cec27f0c49c0701ede06320858c155bf6", "blob": "b61d639675ad84c20014f78ebb9462415092d21a", "ranges": ["2139-2163"], "original": True, "requesters": "A+B", "reason": "complete current-employee-profile eligibility used by accessibleSiteIds"},
    {"path": "app/Models/User.php", "sha256": "0d184ebe6a28395b34b195751ffb62390f4c32828c982103ad613430bc4e59ae", "blob": "fea4665a309992d9098e1e080f4bf11d63fcc675", "ranges": ["416-437"], "original": True, "requesters": "A+B", "reason": "complete permission alias lookup and confirm finance permissions have no synonym expansion"},
    {"path": "app/Domain/Finance/Services/SiteCostService.php", "sha256": "c5eaf1201daad0f00d9e92fe7ac0a086588c421e5832d0d8f0d8526a239cb0c9", "blob": "2fe6e725ec8c1bd3d248bde303269e923d43a3eb", "ranges": ["126-147"], "original": True, "requesters": "A+B", "reason": "complete event-type projection and fallback labeling"},
    {"path": "app/Domain/Finance/Services/CostPerResidentService.php", "sha256": "cd5e97102f2e7c9e8ea087155dbc71c3549c3833526253065269f689ffb8c9e3", "blob": "144d6d996a92e227cd5dfaeeb93e12c236602714", "ranges": ["143-224"], "original": True, "requesters": "A+B", "reason": "follow resident-day, snapshot, and Site-counter occupancy semantics"},
    {"path": "app/Domain/Finance/Services/StaffingCostService.php", "sha256": "f5f8f02c8c7be638f9624faa4f3b4ddf57a93cc7d0df6390e7bbbe8eb0ca51bb", "blob": "1ad8e061ddd06ee7afbc9324d8dd9b11a0400b00", "ranges": ["107-124"], "original": True, "requesters": "A+B", "reason": "complete staffing bundle calculation and zero-wage percentage behavior"},
    {"path": "app/Domain/Finance/Models/FinCostAllocation.php", "sha256": "b1281491091fc4b9d8f1ead0048e376f59f6631fc555e6c0c7ea20d257622bd2", "blob": "a6fba6aff1220d25f3e6a40a93e0323897b90144", "ranges": ["1-83"], "original": False, "requesters": "A+B", "reason": "inspect Site, period, type, and provenance scopes used by every selected aggregate"},
    {"path": "app/Domain/Hr/Models/HrEmployeeProfile.php", "sha256": "f7e196690867bbc7f121e86fc37b0e9b487ed0c32551be7c047712053df9124e", "blob": "d16d598331fa25318dd73bb4e05dda0826f8cc87", "ranges": ["14-19", "83-106", "181-192"], "original": False, "requesters": "A+B", "reason": "inspect soft deletion, effective employment dates, and primary or secondary Site representation"},
    {"path": "app/Domain/Finance/Services/FinancialEventService.php", "sha256": "bacb977edd6119416ec3553fbc378ceb7d9b8a42b4721e890acbc5efd2939a96", "blob": "73b87b70e00a7effb7712bf43045dac10ed7c77f", "ranges": ["67-210"], "original": False, "requesters": "A+B", "reason": "follow normal posted-event allocation creation and reversal deletion"},
    {"path": "app/Domain/Finance/Models/FinFinancialEvent.php", "sha256": "e3792f89d23296d5b6b3bdd4cefa844adb5c2fa2085555537a06119d088d9a65", "blob": "cfedf088a02473cf63cf41180c35848a61776250", "ranges": ["1-172"], "original": False, "requesters": "A+B", "reason": "inspect posted, pending, failed, and reversed provenance available but not joined by the aggregate"},
    {"path": "app/Domain/Finance/Services/PayrollCostAllocationService.php", "sha256": "ed67ce2bd58ee624bafc1c5f28a46aff06d9a97a10f3d291f8170a85278b2d4e", "blob": "475afaa4c1120cf407b35d2b37b7b4acccdf02a3", "ranges": ["245-330", "482-496"], "original": False, "requesters": "A+B", "reason": "follow direct staffing allocation writers with null financial_event_id"},
    {"path": "app/Domain/Finance/Services/AccountsPayableService.php", "sha256": "fc1fb9c2393c1db531c1ea9b04db6f1a943d7cc0166274ccb23e93a297200735", "blob": "b3fd6e265929ee9f70859f1d11f9743b041c1dde", "ranges": ["501-536"], "original": False, "requesters": "A+B", "reason": "follow captured-bill allocation writers with null financial_event_id"},
    {"path": "app/Domain/Finance/Models/FinJournal.php", "sha256": "27455075c067e39e76ef0d29f754ff2d9a684c5bd35af0c9c68deef742e423d8", "blob": "29a17e5baf8d6224efa4122aa902dabb189aa5e7", "ranges": ["1-137"], "original": False, "requesters": "A+B", "reason": "inspect posting status, reversal, currency, and allocation relationships"},
    {"path": "app/Domain/Finance/Models/FinJournalLine.php", "sha256": "8bc6f37faacfcaab72c651c1baa897ef110e2e77a52ac1db102c33ce72bd633e", "blob": "a9b50b65f2cde32223017126ad90894bfbb05929", "ranges": ["1-78"], "original": False, "requesters": "A+B", "reason": "inspect Site, Client, debit, credit, and tax dimensions behind allocations"},
    {"path": "database/migrations/2026_04_09_120000_create_financial_events_system.php", "sha256": "bab4dd11df9f3e51c8e87cf9d17b009f0a2f0db7078475e77a1ee96ee3be5bd8", "blob": "2360a5c2361ed9bf897a76483c932a0a67e070e1", "ranges": ["14-95"], "original": False, "requesters": "A+B", "reason": "inspect nullable allocation dimensions, event statuses, foreign keys, and missing allocation-level status or currency"},
    {"path": "tests/Architecture/FinancialInsightsObjectScopeBoundaryTest.php", "sha256": "0b431c3088af466485dbc0ec79adf7aa390ce26b4b437528d6881207c6b205ae", "blob": "b5472872849d60ead14d9b510114f28ad99cfe97", "ranges": ["1-163"], "original": False, "requesters": "A+B", "reason": "inspect unexecuted static route and resolver-boundary assertions"},
    {"path": "database/migrations/2026_02_17_060000_add_residential_to_sites_type_enum.php", "sha256": "07c3ddf25d3601d972e5a8b109289aee06bbbf1e97e0b75ca902f63974890fb4", "blob": "6585d2c3577862d75ffef2321603eb42f2bc8691", "ranges": ["1-35"], "original": False, "requesters": "A", "reason": "resolve residential as a canonical Site type"},
    {"path": "app/Domain/Finance/Jobs/PostSiteUtilitiesJob.php", "sha256": "cdaa1a6bb917b7caec5a67dee58d0da51de9ddb3218691302848de1cd9695555", "blob": "b676c79eed44bbd028a0c24661f310c885ac72f1", "ranges": ["107-220"], "original": False, "requesters": "A", "reason": "resolve estimate, actual, and negative true-up sign provenance"},
    {"path": "app/Domain/Finance/Services/JournalPostingService.php", "sha256": "092af77653c278507f2bdb10fdcf24b327d511c274b473bb081e813b66f65526", "blob": "4e7817681f3ca3453da1f87a698969d6d575f357", "ranges": ["142-260", "404-493"], "original": False, "requesters": "A", "reason": "reconcile general journal reversal lineage with allocation lifecycle cleanup"},
    {"path": "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php", "sha256": "eba5afc431d883e3d04fd9ce63e9f1009f03f7cb284ca301dcc95ab52303495c", "blob": "8388ccb5fbd0c586fcdbc2b65514c7b43223f016", "ranges": ["18-97", "532-559"], "original": True, "requesters": "A", "reason": "capture omitted actor and allocation fixtures plus explicit global-permission deny and seeding assertions"},
    {"path": "database/migrations/2026_04_09_180000_site_financial_accuracy_hardening.php", "sha256": "4afdbe7797c76fc397faa61c6e6cabfe75bb013547fa0dcdc4da36ebf7f81715", "blob": "4597e1a2a38d437cdb0c4be7fbe3efba4acf3081", "ranges": ["1-50"], "original": False, "requesters": "A", "reason": "inspect utility actual and estimate, actual, and true-up tracking schema"},
    {"path": "database/migrations/2026_03_28_001000_create_fin_journals_table.php", "sha256": "2468357f53468d4744b59432014c60b8a7a17a41644e02846ac2210f89d9dbcb", "blob": "22a6d221f79010b740134ce11f47935227346714", "ranges": ["1-43"], "original": False, "requesters": "A", "reason": "inspect journal status, date, and legacy reversal columns"},
    {"path": "database/migrations/2026_03_28_001100_create_fin_journal_lines_table.php", "sha256": "b44b0c8642ac058236f950c9fe2db5de9b9964fd86e14686c99407d1345b890f", "blob": "ed8dd47f65c2008dd18b70ea3438b21d2616c643", "ranges": ["1-33"], "original": False, "requesters": "A", "reason": "inspect journal-line amount and relationship schema"},
    {"path": "database/migrations/2026_08_14_000063_enforce_fin_journal_reversal_invariant.php", "sha256": "e251c6cb4bb5e12534e39b171c96f72696966208ccb27ed9e557129164f2fa9c", "blob": "463dccf541086b6de2818de1417146223ab4509f", "ranges": ["1-122"], "original": False, "requesters": "A", "reason": "inspect exact journal reversal lineage constraints and legacy reconciliation"},
    {"path": "database/migrations/2026_08_14_000054_add_financial_insights_global_site_permission.php", "sha256": "93d69f3c3b99e45d5d882dfa59457d24eefdf76090901b00ddad1a1a63aac294", "blob": "51392fb792b33314b9fa28126bb3f4bf6c7b2013", "ranges": ["1-37"], "original": False, "requesters": "A", "reason": "inspect explicit global-Site permission and role assignments"},
)


REVIEW_A_LOCI = [
    "03-feature-to-benchmark-matrix.csv:90",
    "routes/web.php:369",
    "routes/finance.php:62",
    "routes/finance.php:93-96",
    "routes/finance.php:776-782",
    "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:53-66",
    "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:194-205",
    "app/Domain/Finance/Services/FinancialInsightsScopeResolver.php:25-70",
    "app/Domain/Finance/Services/SiteFinancialDashboardService.php:88-134",
    "app/Domain/Finance/Services/SiteCostService.php:50-79",
    "app/Domain/Finance/Services/CostPerResidentService.php:43-67",
    "app/Domain/Finance/Services/StaffingCostService.php:81-101",
    "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:22-154",
    "resources/js/pages/finance/sites-overview/Show.tsx:182",
    "resources/js/components/finance/overview-hub.tsx:50",
    "resources/js/pages/sites/_ledger-panel.tsx:831",
    "docs/architecture/financial-insights-object-scope.md:1-41",
    "docs/architecture/single-tenant-application.md:1-21",
]

REVIEW_B_LOCI = [
    "03-feature-to-benchmark-matrix.csv:90",
    "routes/web.php:369",
    "routes/finance.php:62",
    "routes/finance.php:93-96",
    "routes/finance.php:776-782",
    "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:54-66",
    "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:194-204",
    "app/Domain/Finance/Services/FinancialInsightsScopeResolver.php:35-69",
    "app/Domain/Finance/Services/SiteFinancialDashboardService.php:88-134",
    "app/Domain/Finance/Services/SiteCostService.php:50-79",
    "app/Domain/Finance/Services/CostPerResidentService.php:43-68",
    "app/Domain/Finance/Services/StaffingCostService.php:81-123",
]

QUESTION_DISPOSITIONS = {
    "RUN141-Q-IDENTITY-COLLISION": "RESOLVED_OWNER",
    "RUN141-Q-EFFECTIVE-NAME-REACHABILITY": "STATIC_EFFECTIVE_NAMES_RESOLVED_FRAMEWORK_EXECUTION_UNPROVED",
    "RUN141-Q-SITE-SCOPE": "STATIC_BOUNDARY_PRESENT_EXECUTABLE_CORRECTNESS_UNPROVED",
    "RUN141-Q-SITE-TYPE-CONVERGENCE": "PROVISIONAL_MATERIAL_DIVERGENCE",
    "RUN141-Q-PERIOD-CONTRACT": "PROVISIONAL_MATERIAL_DIVERGENCE",
    "RUN141-Q-COST-PROVENANCE": "PROVISIONAL_RISK_REQUIRING_BOUNDED_EXPANSION_AND_EXECUTION",
    "RUN141-Q-CALLER-DISCOVERABILITY": "ZERO_EXACT_FRONTEND_CALLERS_NOT_DEAD_PROOF",
    "RUN141-Q-EXECUTABLE-ASSURANCE": "UNEXECUTED_AND_INCOMPLETE_ZERO_CREDIT",
}

REVIEW_QUESTION_DISPOSITIONS: dict[str, dict[str, str]] = {
    "RUN141R-INDEPENDENT-REVIEW-A": dict(QUESTION_DISPOSITIONS),
    "RUN141R-INDEPENDENT-REVIEW-B": {
        "RUN141-Q-IDENTITY-COLLISION": "RESOLVED_OWNER",
        "RUN141-Q-EFFECTIVE-NAME-REACHABILITY": "STATIC_EFFECTIVE_NAMES_RESOLVED_FRAMEWORK_EXECUTION_UNPROVED",
        "RUN141-Q-SITE-SCOPE": "STATIC_BOUNDARY_LOOKS_BOUNDED_EXECUTABLE_CORRECTNESS_UNPROVED",
        "RUN141-Q-SITE-TYPE-CONVERGENCE": "PROVISIONAL_MATERIAL_DIVERGENCE",
        "RUN141-Q-PERIOD-CONTRACT": "PROVISIONAL_MATERIAL_DIVERGENCE",
        "RUN141-Q-COST-PROVENANCE": "PROVISIONAL_PROVENANCE_REVERSAL_OCCUPANCY_AND_SCALING_RISKS_ZERO_CORRECTNESS_CREDIT",
        "RUN141-Q-CALLER-DISCOVERABILITY": "ZERO_EXACT_FRONTEND_CALLERS_NOT_DEAD_PROOF",
        "RUN141-Q-EXECUTABLE-ASSURANCE": "UNEXECUTED_AND_INCOMPLETE_ZERO_CREDIT",
    },
}

LOCAL_ASSURANCE_OBSERVATION_IDS: dict[str, list[str]] = {
    "RUN141R-INDEPENDENT-REVIEW-A": [
        "RUN141R-A-SITE-SCOPE-RUNTIME-ASSURANCE",
        "RUN141R-A-SITE-TYPE-CONVERGENCE",
        "RUN141R-A-PERIOD-CONTRACT-DIVERGENCE",
        "RUN141R-A-ALLOCATION-PROVENANCE-AND-REVERSAL",
        "RUN141R-A-UTILITY-TRUE-UP-SIGN",
        "RUN141R-A-OCCUPANCY-PERIOD-PRIVACY",
        "RUN141R-A-CALLER-DISCOVERABILITY",
        "RUN141R-A-EXECUTABLE-ASSURANCE",
        "RUN141R-A-SOURCE-PACKET-BOUNDARY",
    ],
    "RUN141R-INDEPENDENT-REVIEW-B": [
        "RUN141R-B-EFFECTIVE-ROUTE-REGISTRATION",
        "RUN141R-B-APPROVED-SITE-SCOPE-EXECUTABLE-ASSURANCE",
        "RUN141R-B-SITE-TYPE-PARITY",
        "RUN141R-B-PERIOD-CONTRACT-DIVERGENCE",
        "RUN141R-B-ALLOCATION-PROVENANCE-CURRENCY-REVERSAL",
        "RUN141R-B-OCCUPANCY-LIFECYCLE",
        "RUN141R-B-PORTFOLIO-QUERY-SCALING",
        "RUN141R-B-CALLER-AND-EXECUTABLE-COVERAGE",
    ],
}

ASSURANCE_FINDINGS: list[dict[str, Any]] = [
    {"finding_id": "RUN141R-ASSURANCE-EFFECTIVE-ROUTE-REGISTRATION", "severity": "EVIDENCE_BOUNDARY", "category": "route_registration_and_effective_name_reachability", "loci": ["routes/web.php:369", "routes/finance.php:62", "routes/finance.php:776-782"], "observation": "Static prefix composition disambiguates finance.api.sites.overview from finance.sites.overview, but framework registration was not executed. This supports static ownership only, not reachability."},
    {"finding_id": "RUN141R-ASSURANCE-APPROVED-SITE-SCOPE", "severity": "P1", "category": "approved_site_scope_and_exact_global_permission", "loci": ["app/Domain/Finance/Services/FinancialInsightsScopeResolver.php:35-69", "app/Services/UserSiteAccessService.php:70-152", "app/Services/UserSiteAccessService.php:2139-2163", "database/migrations/2026_08_14_000054_add_financial_insights_global_site_permission.php:1-37"], "observation": "Source requires finance.dashboard, derives current assigned Sites, and reserves application-wide Site reach for finance.insights.viewAllSites. Relevant tests were not executed, so ordinary, global, no-Site, inactive, and archived cases receive no correctness credit."},
    {"finding_id": "RUN141R-ASSURANCE-SITE-TYPE-PARITY", "severity": "P1", "category": "api_page_site_type_parity", "loci": ["app/Domain/Finance/Services/SiteFinancialDashboardService.php:93-100", "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:42-49", "database/migrations/2026_02_17_060000_add_residential_to_sites_type_enum.php:1-35"], "observation": "The selected API excludes residential Sites while the canonical page route includes house, residential, and facility Sites. That material divergence requires an explicit product and data contract."},
    {"finding_id": "RUN141R-ASSURANCE-PERIOD-CONTRACT", "severity": "P1", "category": "period_validation_and_normalization", "loci": ["app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:194-205", "app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:22-40", "app/Domain/Finance/Models/FinCostAllocation.php:74-77"], "observation": "The API parses free-form dates without page-equivalent validation, inversion correction, or day normalization. Invalid, inverted, timezone, default-range, and end-of-day behavior remains unassured."},
    {"finding_id": "RUN141R-ASSURANCE-ALLOCATION-PROVENANCE-REVERSAL", "severity": "P1", "category": "allocation_provenance_and_reversal_lifecycle", "loci": ["app/Domain/Finance/Models/FinCostAllocation.php:8-83", "app/Domain/Finance/Models/FinFinancialEvent.php:108-165", "app/Domain/Finance/Models/FinJournal.php:24-56", "app/Domain/Finance/Services/FinancialEventService.php:93-209", "app/Domain/Finance/Services/JournalPostingService.php:142-260", "app/Domain/Finance/Services/JournalPostingService.php:404-493"], "observation": "Portfolio totals aggregate allocation rows without proving posted or reversal state, journal-line and Site convergence, currency normalization, or complete cleanup for null-event and general-journal allocations."},
    {"finding_id": "RUN141R-ASSURANCE-UTILITY-TRUE-UP-SIGN", "severity": "P1", "category": "utility_true_up_sign_provenance", "loci": ["app/Domain/Finance/Jobs/PostSiteUtilitiesJob.php:107-220", "database/migrations/2026_04_09_180000_site_financial_accuracy_hardening.php:1-50", "app/Domain/Finance/Models/FinCostAllocation.php:8-83"], "observation": "Negative utility true-ups flow into positive-allocation-shaped cost records without completed sign-provenance proof, creating a material correctness risk that requires bounded execution."},
    {"finding_id": "RUN141R-ASSURANCE-OCCUPANCY-LIFECYCLE-PRIVACY", "severity": "P1", "category": "occupancy_lifecycle_and_privacy", "loci": ["app/Domain/Finance/Services/CostPerResidentService.php:143-224", "app/Models/Client.php:1-430"], "observation": "Resident-day occupancy uses incomplete effective-status history and fallback counters. Inactive, transferred, historical, or soft-deleted Client participation and low-census privacy remain unassured."},
    {"finding_id": "RUN141R-ASSURANCE-PORTFOLIO-QUERY-SCALING", "severity": "P2", "category": "portfolio_query_scaling", "loci": ["app/Domain/Finance/Services/SiteFinancialDashboardService.php:102-128", "app/Domain/Finance/Services/CostPerResidentService.php:43-49", "app/Domain/Finance/Services/SiteCostService.php:50-57"], "observation": "Staffing is batched, but per-Site breakdown and occupancy work grows with Site count and repeats cost calculation. The portfolio query shape was not profiled."},
    {"finding_id": "RUN141R-ASSURANCE-CALLER-EXECUTABLE-COVERAGE", "severity": "P1", "category": "caller_discoverability_and_executable_coverage", "loci": ["resources/js/pages/finance/sites-overview/Show.tsx:182", "resources/js/components/finance/overview-hub.tsx:50", "resources/js/pages/sites/_ledger-panel.tsx:831", "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:18-97", "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:532-559", "tests/Architecture/FinancialInsightsObjectScopeBoundaryTest.php:22-162"], "observation": "The tracked frontend has zero exact selected-API callers and the relevant feature, architecture, and browser evidence was not executed. Caller absence is not dead proof, but intended workflow handoff and critical negative contracts remain unproved."},
]

SHARED_ASSURANCE_FINDINGS: list[dict[str, Any]] = [
    {"finding_id": "RUN141R-SHARED-SOURCE-PACKET-EXPANSION", "severity": "EVIDENCE_BOUNDARY", "category": "source_packet_completeness", "loci": [], "observation": "The review widened six original packet files and followed eighteen new files. The original packet remains explicitly incomplete and no expansion independently authorizes correctness credit."},
    {"finding_id": "RUN141R-SHARED-EXPANSION-LOCUS-CORRECTION", "severity": "EVIDENCE_BOUNDARY", "category": "review_transcription_correction", "loci": ["app/Domain/Finance/Services/JournalPostingService.php:404-493"], "observation": "Reviewer A requested JournalPostingService.php:404-498. The frozen file ends at line 493, so synthesis bounded the only invalid locus to 404-493 and disclosed the correction without changing the owner outcome."},
    {"finding_id": "RUN141R-SHARED-PAGE-SIBLING-CALLER-NONINHERITANCE", "severity": "EVIDENCE_BOUNDARY", "category": "ownership_noninheritance", "loci": ["routes/finance.php:93-96", "resources/js/pages/finance/sites-overview/Show.tsx:182", "resources/js/components/finance/overview-hub.tsx:50", "resources/js/pages/sites/_ledger-panel.tsx:831"], "observation": "The existing page owner, separate pending page route, page-path callers, already reviewed adjacent row, and next pending row remain non-inheritable and uncredited context."},
]

ASSURANCE_FINDING_RECONCILIATION_ROWS: list[dict[str, str]] = [
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-SITE-SCOPE-RUNTIME-ASSURANCE", "assurance_family_id": "RUN141R-FAMILY-APPROVED-SITE-SCOPE", "output_finding_id": "RUN141R-ASSURANCE-APPROVED-SITE-SCOPE", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-SITE-TYPE-CONVERGENCE", "assurance_family_id": "RUN141R-FAMILY-SITE-TYPE-PARITY", "output_finding_id": "RUN141R-ASSURANCE-SITE-TYPE-PARITY", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-PERIOD-CONTRACT-DIVERGENCE", "assurance_family_id": "RUN141R-FAMILY-PERIOD-CONTRACT", "output_finding_id": "RUN141R-ASSURANCE-PERIOD-CONTRACT", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-ALLOCATION-PROVENANCE-AND-REVERSAL", "assurance_family_id": "RUN141R-FAMILY-ALLOCATION-PROVENANCE-REVERSAL", "output_finding_id": "RUN141R-ASSURANCE-ALLOCATION-PROVENANCE-REVERSAL", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-UTILITY-TRUE-UP-SIGN", "assurance_family_id": "RUN141R-FAMILY-UTILITY-TRUE-UP-SIGN", "output_finding_id": "RUN141R-ASSURANCE-UTILITY-TRUE-UP-SIGN", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-OCCUPANCY-PERIOD-PRIVACY", "assurance_family_id": "RUN141R-FAMILY-OCCUPANCY-LIFECYCLE-PRIVACY", "output_finding_id": "RUN141R-ASSURANCE-OCCUPANCY-LIFECYCLE-PRIVACY", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-CALLER-DISCOVERABILITY", "assurance_family_id": "RUN141R-FAMILY-CALLER-EXECUTABLE-COVERAGE", "output_finding_id": "RUN141R-ASSURANCE-CALLER-EXECUTABLE-COVERAGE", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-EXECUTABLE-ASSURANCE", "assurance_family_id": "RUN141R-FAMILY-CALLER-EXECUTABLE-COVERAGE", "output_finding_id": "RUN141R-ASSURANCE-CALLER-EXECUTABLE-COVERAGE", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_a", "local_assurance_observation_id": "RUN141R-A-SOURCE-PACKET-BOUNDARY", "assurance_family_id": "RUN141R-FAMILY-SOURCE-PACKET-BOUNDARY", "output_finding_id": "RUN141R-SHARED-SOURCE-PACKET-EXPANSION", "output_scope": "SHARED"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-EFFECTIVE-ROUTE-REGISTRATION", "assurance_family_id": "RUN141R-FAMILY-EFFECTIVE-ROUTE-REGISTRATION", "output_finding_id": "RUN141R-ASSURANCE-EFFECTIVE-ROUTE-REGISTRATION", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-APPROVED-SITE-SCOPE-EXECUTABLE-ASSURANCE", "assurance_family_id": "RUN141R-FAMILY-APPROVED-SITE-SCOPE", "output_finding_id": "RUN141R-ASSURANCE-APPROVED-SITE-SCOPE", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-SITE-TYPE-PARITY", "assurance_family_id": "RUN141R-FAMILY-SITE-TYPE-PARITY", "output_finding_id": "RUN141R-ASSURANCE-SITE-TYPE-PARITY", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-PERIOD-CONTRACT-DIVERGENCE", "assurance_family_id": "RUN141R-FAMILY-PERIOD-CONTRACT", "output_finding_id": "RUN141R-ASSURANCE-PERIOD-CONTRACT", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-ALLOCATION-PROVENANCE-CURRENCY-REVERSAL", "assurance_family_id": "RUN141R-FAMILY-ALLOCATION-PROVENANCE-REVERSAL", "output_finding_id": "RUN141R-ASSURANCE-ALLOCATION-PROVENANCE-REVERSAL", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-OCCUPANCY-LIFECYCLE", "assurance_family_id": "RUN141R-FAMILY-OCCUPANCY-LIFECYCLE-PRIVACY", "output_finding_id": "RUN141R-ASSURANCE-OCCUPANCY-LIFECYCLE-PRIVACY", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-PORTFOLIO-QUERY-SCALING", "assurance_family_id": "RUN141R-FAMILY-PORTFOLIO-QUERY-SCALING", "output_finding_id": "RUN141R-ASSURANCE-PORTFOLIO-QUERY-SCALING", "output_scope": "ACTION"},
    {"reviewer_task_path": "/root/run141_candidate_reviewer_b", "local_assurance_observation_id": "RUN141R-B-CALLER-AND-EXECUTABLE-COVERAGE", "assurance_family_id": "RUN141R-FAMILY-CALLER-EXECUTABLE-COVERAGE", "output_finding_id": "RUN141R-ASSURANCE-CALLER-EXECUTABLE-COVERAGE", "output_scope": "ACTION"},
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
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def strict_json_loads(text: str) -> Any:
    return json.loads(text, object_pairs_hook=reject_duplicate_keys)


def load_json(path: Path) -> dict[str, Any]:
    value = strict_json_loads(path.read_text(encoding="utf-8"))
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    result = subprocess.run(["git", *args], cwd=REPO, check=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return result.stdout.strip()


def current_dirty_paths() -> set[str]:
    paths: set[str] = set()
    for row in git("status", "--porcelain=v1", "--untracked-files=all").splitlines():
        assert len(row) >= 4, row
        path = row[3:].replace("\\", "/")
        if " -> " in path:
            paths.update(path.split(" -> ", 1))
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
    return path, int(match.group(1)), int(match.group(2) or match.group(1))


def assert_locus(locus: str) -> None:
    path, start, end = locus_path_and_range(locus)
    line_count = len(path.read_text(encoding="utf-8-sig").splitlines())
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
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database") == ""
    assert not list(AUDIT_DIR.rglob("__pycache__"))
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(COHORT_GENERATOR) == COHORT_GENERATOR_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256


def expansion_manifest_sha256() -> str:
    lines = []
    for spec in EXPANSION_SPECS:
        lines.append("|".join([
            spec["path"], spec["sha256"], spec["blob"], spec["blob"],
            ",".join(spec["ranges"]), str(spec["original"]).lower(), spec["requesters"],
            "outcome_material=false", "correctness_only=true",
        ]))
    return sha256_bytes(("\n".join(lines) + "\n").encode("utf-8"))


def build_expansions(cohort: dict[str, Any]) -> list[dict[str, Any]]:
    packet_by_path = {row["path"]: row for row in cohort["source_review_packet"]["required_source_files"]}
    expansions: list[dict[str, Any]] = []
    for spec in EXPANSION_SPECS:
        path = REPO / spec["path"]
        assert path.is_file(), spec["path"]
        assert sha256_file(path) == spec["sha256"], spec["path"]
        assert git("rev-parse", f"HEAD:{spec['path']}") == spec["blob"], spec["path"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{spec['path']}") == spec["blob"], spec["path"]
        original = packet_by_path.get(spec["path"])
        assert (original is not None) is spec["original"], spec["path"]
        expanded_loci = [f"{spec['path']}:{line_range}" for line_range in spec["ranges"]]
        for locus in expanded_loci:
            assert_locus(locus)
        expansions.append({
            "path": spec["path"],
            "sha256": spec["sha256"],
            "head_blob_id": spec["blob"],
            "application_commit_blob_id": spec["blob"],
            "head_matches_application_commit_blob": True,
            "original_packet_present": spec["original"],
            "original_review_loci": original["review_loci"] if original else [],
            "expanded_review_loci": expanded_loci,
            "expansion_reason": spec["reason"],
            "requesters": spec["requesters"],
            "outcome_material": False,
            "correctness_only": True,
            "expansion_changes_original_packet_bytes": False,
            "expansion_authorizes_correctness_credit": False,
        })
    assert len(expansions) == len({row["path"] for row in expansions}) == 24
    assert Counter(row["original_packet_present"] for row in expansions) == {True: 6, False: 18}
    assert Counter(row["requesters"] for row in expansions) == {"A+B": 15, "A": 9}
    assert expansion_manifest_sha256() == EXPANSION_UNION_MANIFEST_SHA256
    assert len((REPO / "app/Domain/Finance/Services/JournalPostingService.php").read_text(encoding="utf-8").splitlines()) == 493
    return expansions


def tracked_scan_receipts() -> dict[str, Any]:
    tracked = [path for path in git("ls-files", "resources/js").splitlines() if Path(path).suffix.lower() in {".js", ".jsx", ".ts", ".tsx"}]
    tracked_sorted = sorted(tracked, key=str.casefold)
    tracked_digest = sha256_bytes(("\n".join(tracked_sorted) + "\n").encode("utf-8"))
    exact_callers = []
    for relative in tracked:
        text = (REPO / relative).read_text(encoding="utf-8-sig")
        if "finance.api.sites.overview" in text or "/finance/api/sites/overview" in text:
            exact_callers.append(relative)
    browser_files = sorted(
        [path for path in git("ls-files", "tests/Browser/Finance").splitlines() if Path(path).suffix.lower() == ".php"],
        key=str.casefold,
    )
    browser_digest = sha256_bytes(("\n".join(browser_files) + "\n").encode("utf-8"))
    assert len(tracked) == 1965 and tracked_digest == TRACKED_JS_TS_FILE_LIST_SHA256
    assert exact_callers == []
    assert len(browser_files) == 8 and browser_digest == FINANCE_BROWSER_FILE_LIST_SHA256
    assert not any("finance.api.sites.overview" in (REPO / path).read_text(encoding="utf-8-sig") or "/finance/api/sites/overview" in (REPO / path).read_text(encoding="utf-8-sig") for path in browser_files)
    return {
        "tracked_resources_js_files_scanned": len(tracked),
        "tracked_resources_js_file_list_sha256": tracked_digest,
        "selected_api_exact_frontend_caller_occurrences": 0,
        "finance_browser_test_files_scanned": len(browser_files),
        "finance_browser_test_file_list_sha256": browser_digest,
        "selected_api_finance_browser_occurrences": 0,
        "caller_absence_is_dead_or_noncanonical_proof": False,
    }


def normalized_review(
    review_id: str,
    task_path: str,
    loci: list[str],
    identity_basis: str,
    rationale: str,
    observations: list[str],
    expansions: list[dict[str, Any]],
) -> dict[str, Any]:
    for locus in loci:
        assert_locus(locus)
    requested_expansions = [
        row["path"]
        for row in expansions
        if review_id.removeprefix("RUN141R-INDEPENDENT-REVIEW-") in row["requesters"].split("+")
    ]
    expected_expansion_count = 24 if review_id.endswith("-A") else 15
    assert len(requested_expansions) == len(set(requested_expansions)) == expected_expansion_count
    question_dispositions = REVIEW_QUESTION_DISPOSITIONS[review_id]
    assert set(question_dispositions) == set(QUESTION_DISPOSITIONS) and len(question_dispositions) == 8
    local_observation_ids = LOCAL_ASSURANCE_OBSERVATION_IDS[review_id]
    assert len(local_observation_ids) == len(set(local_observation_ids))
    review: dict[str, Any] = {
        "review_id": review_id,
        "reviewer_task_path": task_path,
        "candidate_id": CANDIDATE_ID,
        "candidate_record_sha256": CANDIDATE_RECORD_SHA256,
        "queue_index_zero_based": 78,
        "queue_id": "RUN090-ROUTE-0079",
        "queue_canonical_key": "route|RUN077-ROUTE-0669",
        "route_record_id": "RUN077-ROUTE-0669",
        "source_key": "routes/finance.php:781:9:get:258",
        "literal_route_name": "sites.overview",
        "effective_route_name": "finance.api.sites.overview",
        "effective_uri": "/finance/api/sites/overview",
        "candidate_feature_id": FEATURE_ID,
        "controller_file": BRIDGE_KEY[0],
        "controller_method": BRIDGE_KEY[1],
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_STATIC_IDENTITY_ONLY",
        "identity_basis": identity_basis,
        "source_loci": loci,
        "rationale": rationale,
        "material_observations": observations,
        "question_dispositions": question_dispositions,
        "question_disposition_count": len(question_dispositions),
        "question_dispositions_sha256": canonical_json_sha256(question_dispositions),
        "requested_expansion_paths": requested_expansions,
        "requested_expansion_path_count": len(requested_expansions),
        "requested_expansion_path_list_sha256": canonical_list_sha256(requested_expansions),
        "source_packet_expansion_union_manifest_sha256": EXPANSION_UNION_MANIFEST_SHA256,
        "local_assurance_observation_ids": local_observation_ids,
        "local_assurance_observation_id_count": len(local_observation_ids),
        "local_assurance_observation_id_list_sha256": canonical_list_sha256(local_observation_ids),
        "blinded_review": True,
        "other_candidate_reviewer_consulted": False,
        "synthesis_reviewer_consulted": False,
        "noninheritance_flags": {
            "existing_page_owner_inherited_or_recredited": False,
            "sibling_route_identity_or_outcome_inherited": False,
            "page_path_callers_inherited": False,
            "excluded_neighbor_79_recredited": False,
            "next_pending_80_selected_or_credited": False,
            "page_ownership_authorized": False,
            "current_overlay_credit_awarded": False,
            "correctness_credit_authorized": False,
            "downstream_credit_authorized": False,
        },
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
    return review


def build() -> dict[str, Any]:
    assert_workspace()
    cohort = load_json(COHORT_PATH)
    assert cohort["run_id"] == "RUN-141-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-COHORT-WAVE-23"
    assert cohort["status"] == "ONE_NAME_ONLY_FINANCE_SITE_PORTFOLIO_JSON_ROUTE_ACTION_CANDIDATE_PENDING_FRESH_REVIEW_ZERO_CREDIT"
    assert cohort["pins"]["checkpoint_commit"] == "61d544240837bdceabd126de1595729927db2177"
    assert cohort["pins"]["checkpoint_tree"] == "8b64cacdcb88c9141cc068943e8628694da43d28"
    assert cohort["pins"]["application_commit"] == APPLICATION_COMMIT
    assert cohort["pins"]["application_tree"] == APPLICATION_TREE
    packet = cohort["source_review_packet"]
    assert packet["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert packet["required_source_file_identity_sha256"] == REQUIRED_SOURCE_IDENTITY_SHA256
    assert packet["required_source_file_count"] == 25
    assert packet["material_dependency_method_slice_count"] == 14
    assert len(packet["known_excluded_expansion_candidates"]) == 6
    assert packet["source_review_complete"] is False
    assert packet["source_packet_completeness_claimed"] is False
    assert packet["material_dependency_semantics_complete"] is False
    assert packet["known_expansion_candidates_adjudicated"] is False
    assert cohort["fresh_review_contract"]["required_independent_candidate_reviews"] == 2
    assert cohort["fresh_review_contract"]["required_cohort_synthesis"] == 1
    for row in packet["required_source_files"]:
        path = REPO / row["path"]
        assert path.is_file() and sha256_file(path) == row["sha256"], row["path"]
        head_blob = git("rev-parse", f"HEAD:{row['path']}")
        app_blob = git("rev-parse", f"{APPLICATION_COMMIT}:{row['path']}")
        assert head_blob == app_blob == row["blob_id"] == row["application_commit_blob_id"], row["path"]

    assert len(cohort["records"]) == 1
    candidate = cohort["records"][0]
    candidate_digest_source = dict(candidate)
    assert candidate_digest_source.pop("candidate_record_sha256") == CANDIDATE_RECORD_SHA256
    assert canonical_json_sha256(candidate_digest_source) == CANDIDATE_RECORD_SHA256
    assert candidate["candidate_id"] == CANDIDATE_ID
    assert candidate["action_key"] == ACTION_KEY
    assert candidate["queue_index_zero_based"] == 78
    assert candidate["queue_id"] == "RUN090-ROUTE-0079"
    assert candidate["queue_canonical_key"] == "route|RUN077-ROUTE-0669"
    assert candidate["candidate_feature_id"] == FEATURE_ID
    assert candidate["name_only_identity"]["relation_comparison"] == "NAME_ONLY"
    assert candidate["name_only_identity"]["name_candidate_feature_ids"] == [FEATURE_ID]
    assert candidate["name_only_identity"]["backend_candidate_count"] == 0
    assert candidate["name_only_identity"]["unique_controller_resolution_is_review_context_not_feature_identity"] is True
    assert candidate["fresh_review_state"]["status"] == "PENDING"
    assert candidate["collision_checks"]["previous_review_source_collision"] is False
    assert candidate["collision_checks"]["current_owner_source_collision"] is False
    assert candidate["collision_checks"]["existing_controller_action_bridge_collision"] is False
    assert candidate["collision_checks"]["duplicate_local_route_name_context_present"] is True
    assert candidate["controller_action"]["literal_inertia_page_callsite_count"] == 0
    assert candidate["controller_action"]["returns_json_response"] is True
    route = candidate["route_source"]
    primary = candidate["controller_action"]["primary_method_slice"]
    assert route["route_record_id"] == "RUN077-ROUTE-0669"
    assert route["literal_route_name"] == "sites.overview"
    assert route["static_group_context"]["derived_name"] == "finance.api.sites.overview"
    assert route["static_group_context"]["derived_uri"] == "/finance/api/sites/overview"
    assert route["source_anchor"] == "routes/finance.php:781"
    assert primary["source_file"] == BRIDGE_KEY[0] and primary["method"] == "sitesOverview" and primary["definition_line"] == 58

    context = cohort["page_sibling_and_caller_context_non_inheritable"]
    assert context["existing_page_owner_record"]["source_record_id"] == "PAGE-ROOT-FC2C5F5706FD9066"
    assert context["existing_page_owner_record"]["owner_row_id"] == "RUN086-PAGE-MAP-0313"
    assert context["separate_page_route_sibling"]["queue_index_zero_based"] == 40
    assert context["separate_page_route_sibling"]["queue_id"] == "RUN090-ROUTE-0041"
    assert context["separate_page_route_sibling"]["route_record_id"] == "RUN077-ROUTE-0418"
    assert context["separate_page_route_sibling"]["effective_route_name"] == "finance.sites.overview"
    assert context["separate_page_route_sibling"]["current_review_state"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert context["page_path_caller_context_count"] == 3
    assert context["selected_api_frontend_exact_caller_occurrence_count"] == 0
    assert context["api_frontend_caller_absence_is_not_dead_or_noncanonical_proof"] is True
    assert context["new_page_owner_records"] == 0
    assert context["page_ownership_inherited"] is False and context["page_ownership_reassigned"] is False
    excluded = cohort["excluded_immediate_raw_neighbor"]
    assert excluded == {"queue_index_zero_based": 79, "queue_id": "RUN090-ROUTE-0080", "route_record_id": "RUN077-ROUTE-0688", "candidate_feature_id": "CAP-FLEET-DAILY-VEHICLE-CHECK", "reviewed_owner_origin": "run098_overlay", "reviewed_outcome": "OWNER_ROUTE_ACTION", "existing_bridge_id": "RUN098-BRIDGE-07", "already_reviewed": True, "selected_for_run141": False, "recredit_authorized": False}
    next_pending = cohort["next_pending_boundary"]
    assert next_pending["queue_index_zero_based"] == 80 and next_pending["queue_id"] == "RUN090-ROUTE-0081" and next_pending["route_record_id"] == "RUN077-ROUTE-0689"
    assert next_pending["selected_for_run141"] is False and next_pending["credit_awarded"] is False

    expansions = build_expansions(cohort)
    scans = tracked_scan_receipts()
    for finding in [*ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]:
        for locus in finding["loci"]:
            assert_locus(locus)
        finding["correctness_credit_authorized"] = False
        finding["final_finding_credit_authorized"] = False
    finding_ids = [row["finding_id"] for row in [*ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]]
    assert len(ASSURANCE_FINDINGS) == 9 and len(SHARED_ASSURANCE_FINDINGS) == 3
    assert len(finding_ids) == len(set(finding_ids)) == 12

    independent_reviews = [
        normalized_review(
            "RUN141R-INDEPENDENT-REVIEW-A",
            "/root/run141_candidate_reviewer_a",
            REVIEW_A_LOCI,
            "NAME_ONLY_EXACT_LITERAL_ROUTE_NAME_PLUS_DIRECT_SEMANTIC_ACTION_TRACE",
            "The exact local route token supplies the sole matrix candidate. sitesOverview resolves aggregate approved-Site scope and directly returns per-Site identity, total cost, cost per resident, occupancy, and staffing summaries sorted by total cost. Distinct effective names, URIs, and controllers separate the duplicate local token; correctness gaps and missing execution do not convert this direct static owner into shared or gap.",
            ["All eight provisional questions were dispositioned; only identity changes ownership.", "Page, sibling, caller, neighbor, and next-boundary context remains non-inheritable and uncredited."],
            expansions,
        ),
        normalized_review(
            "RUN141R-INDEPENDENT-REVIEW-B",
            "/root/run141_candidate_reviewer_b",
            REVIEW_B_LOCI,
            "NAME_ONLY_EXACT_LITERAL_ROUTE_NAME_PLUS_DIRECT_USER_JOB_SEMANTICS",
            "The case-sensitive local token sites.overview supplies the sole matrix feature candidate. Static group composition disambiguates the selected finance.api.sites.overview JSON route from the separate finance.sites.overview page route. The selected action resolves aggregate Site scope and directly returns the multi-Site comparison required by the frozen user job; absent frontend callers affect discoverability, not owner identity.",
            ["Static Site scope looks bounded, but executable authorization and privacy correctness remain unproved.", "Site-type, period, provenance, occupancy, query-scaling, caller, and execution concerns remain assurance evidence only."],
            expansions,
        ),
    ]
    assert len({row["reviewer_task_path"] for row in independent_reviews}) == 2
    assert Counter(row["outcome"] for row in independent_reviews) == {"OWNER_ROUTE_ACTION": 2}
    assert not any(row["reviewer_wrote_files"] for row in independent_reviews)
    assert [row["question_disposition_count"] for row in independent_reviews] == [8, 8]
    assert [row["requested_expansion_path_count"] for row in independent_reviews] == [24, 15]
    assert [row["local_assurance_observation_id_count"] for row in independent_reviews] == [9, 8]
    assert all(row["blinded_review"] for row in independent_reviews)
    assert not any(row["other_candidate_reviewer_consulted"] or row["synthesis_reviewer_consulted"] for row in independent_reviews)
    assert not any(any(row["noninheritance_flags"].values()) for row in independent_reviews)

    reviewer_local_ids = {
        row["reviewer_task_path"]: set(row["local_assurance_observation_ids"])
        for row in independent_reviews
    }
    mapping_input_keys = [
        f"{row['reviewer_task_path']}|{row['local_assurance_observation_id']}"
        for row in ASSURANCE_FINDING_RECONCILIATION_ROWS
    ]
    expected_input_keys = [
        f"{review['reviewer_task_path']}|{local_id}"
        for review in independent_reviews
        for local_id in review["local_assurance_observation_ids"]
    ]
    assert Counter(mapping_input_keys) == Counter(expected_input_keys)
    assert len(mapping_input_keys) == len(set(mapping_input_keys)) == 17
    action_finding_ids = {row["finding_id"] for row in ASSURANCE_FINDINGS}
    shared_finding_ids = {row["finding_id"] for row in SHARED_ASSURANCE_FINDINGS}
    for row in ASSURANCE_FINDING_RECONCILIATION_ROWS:
        assert row["local_assurance_observation_id"] in reviewer_local_ids[row["reviewer_task_path"]]
        if row["output_scope"] == "ACTION":
            assert row["output_finding_id"] in action_finding_ids
        else:
            assert row["output_scope"] == "SHARED" and row["output_finding_id"] in shared_finding_ids
    mapped_action_ids = {
        row["output_finding_id"] for row in ASSURANCE_FINDING_RECONCILIATION_ROWS if row["output_scope"] == "ACTION"
    }
    mapped_shared_ids = {
        row["output_finding_id"] for row in ASSURANCE_FINDING_RECONCILIATION_ROWS if row["output_scope"] == "SHARED"
    }
    assert mapped_action_ids == action_finding_ids and len(mapped_action_ids) == 9
    assert mapped_shared_ids == {"RUN141R-SHARED-SOURCE-PACKET-EXPANSION"}
    synthesis_only_shared_ids = sorted(shared_finding_ids - mapped_shared_ids)
    assert synthesis_only_shared_ids == [
        "RUN141R-SHARED-EXPANSION-LOCUS-CORRECTION",
        "RUN141R-SHARED-PAGE-SIBLING-CALLER-NONINHERITANCE",
    ]
    assurance_finding_reconciliation: dict[str, Any] = {
        "input_rows": ASSURANCE_FINDING_RECONCILIATION_ROWS,
        "reviewer_a_input_observations": 9,
        "reviewer_b_input_observations": 8,
        "total_input_observations": 17,
        "action_output_findings": 9,
        "shared_output_findings": 1,
        "unique_output_findings": 10,
        "unmapped_input_observations": 0,
        "multiply_mapped_input_observations": 0,
        "mapped_action_finding_ids": sorted(mapped_action_ids),
        "mapped_shared_finding_ids": sorted(mapped_shared_ids),
        "synthesis_only_shared_finding_ids": synthesis_only_shared_ids,
        "input_id_list_sha256": canonical_list_sha256(mapping_input_keys),
        "mapping_rows_sha256": canonical_json_sha256(ASSURANCE_FINDING_RECONCILIATION_ROWS),
        "action_findings_sha256": canonical_json_sha256(ASSURANCE_FINDINGS),
        "shared_findings_sha256": canonical_json_sha256(SHARED_ASSURANCE_FINDINGS),
        "combined_findings_sha256": canonical_json_sha256([*ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]),
    }
    correction_records = [{
        "reviewer_task_path": "/root/run141_candidate_reviewer_a",
        "path": "app/Domain/Finance/Services/JournalPostingService.php",
        "requested_locus": "404-498",
        "corrected_locus": "404-493",
        "reason": "frozen file ends at line 493",
        "outcome_changed": False,
    }]
    unresolved_correctness_only_boundaries = [
        "No matching Finance browser file for the selected API was found.",
        "The production-wide FinCostAllocation writer and deletion census remains only partially resolved.",
        "General-journal reversal versus null-event allocation cleanup was requested but not fully adjudicated.",
    ]

    synthesis_review: dict[str, Any] = {
        "reviewer_task_path": "/root/run141_review_synthesis",
        "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
        "accepted_independent_review_ids": [row["review_id"] for row in independent_reviews],
        "accepted_independent_review_record_sha256s": [row["independent_review_record_sha256"] for row in independent_reviews],
        "accepted_candidate_ids": [CANDIDATE_ID],
        "accepted_candidate_record_sha256s": [CANDIDATE_RECORD_SHA256],
        "outcome_variables": {"O": 1, "S": 0, "A": 0, "D": 0, "E": 0},
        "independent_reviews_reconciled": True,
        "outcome_discrepancies": 0,
        "identity_or_key_discrepancies": 0,
        "page_credit_discrepancies": 0,
        "hard_stop_discrepancies": 0,
        "source_packet_expansion_disclosed": True,
        "source_packet_expansion_union_files": 24,
        "source_packet_expansion_union_manifest_sha256": EXPANSION_UNION_MANIFEST_SHA256,
        "source_packet_expansions_sha256": canonical_json_sha256(expansions),
        "source_packet_expansion_locus_corrections": 1,
        "deduplicated_assurance_families": 9,
        "independent_question_dispositions_sha256": canonical_json_sha256([
            row["question_dispositions"] for row in independent_reviews
        ]),
        "independent_local_assurance_observation_ids_sha256": canonical_json_sha256([
            row["local_assurance_observation_ids"] for row in independent_reviews
        ]),
        "independent_requested_expansion_paths_sha256": canonical_json_sha256([
            row["requested_expansion_paths"] for row in independent_reviews
        ]),
        "assurance_finding_reconciliation_sha256": canonical_json_sha256(assurance_finding_reconciliation),
        "action_assurance_findings_sha256": canonical_json_sha256(ASSURANCE_FINDINGS),
        "shared_assurance_findings_sha256": canonical_json_sha256(SHARED_ASSURANCE_FINDINGS),
        "combined_assurance_findings_sha256": canonical_json_sha256([*ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]),
        "source_packet_expansion_locus_correction_records_sha256": canonical_json_sha256(correction_records),
        "unresolved_correctness_only_boundaries_sha256": canonical_json_sha256(unresolved_correctness_only_boundaries),
        "unresolved_boundaries_are_outcome_material": False,
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "page_ownership_authorized": False,
        "prior_page_owner_context_inherited_or_recredited": False,
        "sibling_neighbor_or_next_boundary_inherited_or_recredited": False,
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
        "accepted_independent_review_record_sha256s": synthesis_review["accepted_independent_review_record_sha256s"],
        "synthesis_record_sha256": synthesis_review["synthesis_record_sha256"],
        "queue_index_zero_based": 78,
        "queue_id": "RUN090-ROUTE-0079",
        "queue_canonical_key": "route|RUN077-ROUTE-0669",
        "route_record_id": route["route_record_id"],
        "source_key": route["source_key"],
        "literal_route_name": route["literal_route_name"],
        "effective_route_name": route["static_group_context"]["derived_name"],
        "effective_uri": route["static_group_context"]["derived_uri"],
        "action_key": ACTION_KEY,
        "candidate_feature_id": FEATURE_ID,
        "controller_file": primary["source_file"],
        "controller_method": primary["method"],
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_STATIC_IDENTITY_ONLY_2_OF_2_PLUS_SYNTHESIS",
        "identity_basis": "NAME_ONLY_EXACT_LITERAL_ROUTE_NAME_PLUS_DIRECT_SEMANTIC_ACTION_TRACE",
        "source_loci": sorted(set(REVIEW_A_LOCI + REVIEW_B_LOCI)),
        "material_dependencies": ["finance.dashboard base permission", "finance.insights.viewAllSites global reach", "aggregate approved-Site resolution", "Site financial cost, resident, occupancy, and staffing projections", "period parsing", "separate page route and preserved page-owner context"],
        "rationale": "Both blinded reviewers and the distinct synthesizer agree that the exact name-only feature identity plus the direct multi-Site comparison action establish one bounded static route owner and one controller-action bridge. The 24-file correctness-only expansion does not create page or downstream credit.",
        "review_discrepancies": ["Zero outcome, identity, key, page-credit, or hard-stop discrepancies remain.", "One invalid reviewer locus was bounded from 404-498 to the frozen file EOF at 404-493 and disclosed without changing outcome."],
        "assurance_findings": ASSURANCE_FINDINGS,
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "owner_source_record_key": OWNER_SOURCE_RECORD_KEY,
        "bridge_key": BRIDGE_KEY,
        "page_ownership_authorized": False,
        "prior_page_owner_context_inherited_or_recredited": False,
        "sibling_neighbor_or_next_boundary_inherited_or_recredited": False,
        "site_permission_privacy_direct_object_query_projection_period_lifecycle_concurrency_correctness_authorized": False,
        "runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized": False,
        "current_overlay_credit_awarded": False,
        "reviewer_wrote_files": False,
    }
    decision["decision_record_sha256"] = canonical_json_sha256(decision)
    action_decisions = [decision]

    baseline = cohort["current_baseline"]
    assert baseline["source_owner_records"] == 661 and baseline["route_owner_records"] == 304 and baseline["page_owner_records"] == 357
    assert baseline["static_controller_action_bridges"] == 92 and baseline["bounded_static_source_residual_records"] == 3268
    assert baseline["reviewed_queue_surface_rows"] == 115 and baseline["pending_unreviewed_queue_surface_rows"] == 392
    projection = {
        "O": 1, "S": 0, "A": 0, "D": 0, "E": 0,
        "source_owner_records": 662, "route_owner_records": 305, "page_owner_records": 357,
        "static_controller_action_bridges": 93,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_residual_records": 3267,
        "bounded_static_source_ownership_percent": str((Decimal(662) * Decimal(100) / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)),
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 116, "owner_queue_surface_rows": 94, "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5, "dead_queue_surface_rows": 0, "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 391, "queue_surfaces_without_ownership": 413,
        "residual_explicit_unmapped_routes": 2896, "semantic_shared_routes": 12, "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0, "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345, "semantic_shared_page_roots": 9,
        "evidence_gap_page_roots_tagged_within_residual": 1,
        "distinct_feature_ids": 256, "distinct_H_feature_ids": 234, "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 64, "page_distinct_feature_ids": 242, "route_page_feature_overlap": 50,
        "matrix_rows_changed": 0, "matrix_cells_changed": 0, "projection_credit_awarded": False,
    }
    assert projection["O"] + projection["S"] + projection["A"] + projection["D"] + projection["E"] == 1
    assert projection["source_owner_records"] + projection["bounded_static_source_residual_records"] == 3929
    assert projection["source_owner_records"] == projection["route_owner_records"] + projection["page_owner_records"]
    assert projection["bounded_static_source_ownership_percent"] == "16.849071"
    assert projection["direct_exact_queue_records"] == projection["reviewed_queue_surface_rows"] + projection["pending_unreviewed_queue_surface_rows"]
    assert projection["reviewed_queue_surface_rows"] == projection["owner_queue_surface_rows"] + projection["shared_queue_surface_rows"] + projection["alias_queue_surface_rows"] + projection["dead_queue_surface_rows"] + projection["evidence_gap_queue_surface_rows"]
    assert projection["queue_surfaces_without_ownership"] == projection["pending_unreviewed_queue_surface_rows"] + projection["shared_queue_surface_rows"] + projection["alias_queue_surface_rows"] + projection["dead_queue_surface_rows"] + projection["evidence_gap_queue_surface_rows"]
    assert 3218 == 305 + 12 + 5 + 0 + 2896
    assert 711 == 357 + 9 + 345

    selected_action_evidence = {
        "route_record_id": route["route_record_id"],
        "route_source_anchor": route["source_anchor"],
        "effective_route_name": route["static_group_context"]["derived_name"],
        "effective_uri": route["static_group_context"]["derived_uri"],
        "controller_file": primary["source_file"],
        "controller_method": primary["method"],
        "primary_action_definition_anchor": primary["definition_anchor"],
        "primary_action_review_slice_sha256": primary["review_slice"]["text_sha256"],
        "literal_inertia_page_callsites": candidate["controller_action"]["literal_inertia_page_callsites"],
        "literal_inertia_page_callsite_count": candidate["controller_action"]["literal_inertia_page_callsite_count"],
        "returns_json_response": candidate["controller_action"]["returns_json_response"],
    }
    assert selected_action_evidence["literal_inertia_page_callsites"] == []
    assert selected_action_evidence["literal_inertia_page_callsite_count"] == 0
    assert selected_action_evidence["returns_json_response"] is True

    feature_set_reconciliation = {
        "selected_feature_id": FEATURE_ID,
        "selected_feature_exists_in_current_owner_set_before_review": True,
        "selected_feature_exists_in_current_page_owner_set_before_review": True,
        "selected_feature_exists_in_current_route_owner_set_before_review": False,
        "selected_feature_is_new_distinct_feature": False,
        "current_distinct_feature_ids": baseline["distinct_feature_ids"],
        "projected_distinct_feature_ids_if_integrated": projection["distinct_feature_ids"],
        "current_route_distinct_feature_ids": baseline["route_distinct_feature_ids"],
        "projected_route_distinct_feature_ids_if_integrated": projection["route_distinct_feature_ids"],
        "current_page_distinct_feature_ids": baseline["page_distinct_feature_ids"],
        "projected_page_distinct_feature_ids_if_integrated": projection["page_distinct_feature_ids"],
        "current_route_page_feature_overlap": baseline["route_page_feature_overlap"],
        "projected_route_page_feature_overlap_if_integrated": projection["route_page_feature_overlap"],
        "prior_page_owner_preserved": True,
        "projection_credit_awarded": False,
    }
    assert feature_set_reconciliation["current_distinct_feature_ids"] == feature_set_reconciliation["projected_distinct_feature_ids_if_integrated"] == 256
    assert feature_set_reconciliation["current_route_distinct_feature_ids"] + 1 == feature_set_reconciliation["projected_route_distinct_feature_ids_if_integrated"] == 64
    assert feature_set_reconciliation["current_page_distinct_feature_ids"] == feature_set_reconciliation["projected_page_distinct_feature_ids_if_integrated"] == 242
    assert feature_set_reconciliation["current_route_page_feature_overlap"] + 1 == feature_set_reconciliation["projected_route_page_feature_overlap_if_integrated"] == 50

    page_reconciliation = {
        "selected_action_evidence": selected_action_evidence,
        "existing_page_owner_context": context["existing_page_owner_record"],
        "existing_page_owner_credit_preserved": True,
        "separate_page_route_sibling": context["separate_page_route_sibling"],
        "page_path_caller_contexts": context["page_path_caller_contexts"],
        "selected_api_exact_frontend_caller_occurrences": scans["selected_api_exact_frontend_caller_occurrences"],
        "page_ownership_inherited_reassigned_or_recredited": False,
        "sibling_route_identity_or_outcome_inherited": False,
        "caller_presence_preselected_route_outcome": False,
        "excluded_immediate_raw_neighbor": excluded,
        "excluded_adjacent_row_recredited": False,
        "next_pending_boundary": next_pending,
        "next_pending_boundary_changed_or_credited": False,
        "current_overlay_credit_awarded": False,
    }

    verified_counts = {
        "independent_candidate_reviews": len(independent_reviews),
        "cohort_synthesis_reviews": 1,
        "total_fresh_semantic_reviews": len(independent_reviews) + 1,
        "unique_reviewed_candidates": len({row["candidate_id"] for row in independent_reviews}),
        "reviewed_route_actions": len(action_decisions),
        "owner_route_actions": sum(row["outcome"] == "OWNER_ROUTE_ACTION" for row in action_decisions),
        "accepted_route_records": sum(row["route_ownership_authorized"] for row in action_decisions),
        "accepted_controller_action_bridges": sum(row["controller_action_bridge_authorized"] for row in action_decisions),
        "accepted_page_records": sum(row["page_ownership_authorized"] for row in action_decisions),
        "accepted_distinct_feature_ids": len({row["candidate_feature_id"] for row in action_decisions}),
        "new_distinct_feature_ids": int(feature_set_reconciliation["selected_feature_is_new_distinct_feature"]),
        "selected_controller_literal_inertia_page_callsites": len(selected_action_evidence["literal_inertia_page_callsites"]),
        "existing_page_owner_context_rows": int(bool(page_reconciliation["existing_page_owner_context"])),
        "separate_page_route_sibling_context_rows": int(bool(page_reconciliation["separate_page_route_sibling"])),
        "page_path_caller_contexts": len(page_reconciliation["page_path_caller_contexts"]),
        "selected_api_frontend_exact_caller_occurrences": page_reconciliation["selected_api_exact_frontend_caller_occurrences"],
        "source_packet_expansion_files": len(expansions),
        "source_packet_expansion_existing_files": sum(row["original_packet_present"] for row in expansions),
        "source_packet_expansion_new_files": sum(not row["original_packet_present"] for row in expansions),
        "source_packet_expansion_locus_corrections": len(correction_records),
        "independent_question_dispositions": sum(row["question_disposition_count"] for row in independent_reviews),
        "reviewer_requested_expansion_references": sum(row["requested_expansion_path_count"] for row in independent_reviews),
        "local_assurance_observations": sum(row["local_assurance_observation_id_count"] for row in independent_reviews),
        "assurance_reconciliation_input_rows": len(assurance_finding_reconciliation["input_rows"]),
        "deduplicated_assurance_families": len(ASSURANCE_FINDINGS),
        "shared_assurance_findings": len(SHARED_ASSURANCE_FINDINGS),
        "assurance_evidence_records": len(ASSURANCE_FINDINGS) + len(SHARED_ASSURANCE_FINDINGS),
        "mapped_action_assurance_outputs": assurance_finding_reconciliation["action_output_findings"],
        "mapped_shared_assurance_outputs": assurance_finding_reconciliation["shared_output_findings"],
        "unique_mapped_assurance_outputs": assurance_finding_reconciliation["unique_output_findings"],
        "unmapped_assurance_inputs": assurance_finding_reconciliation["unmapped_input_observations"],
        "multiply_mapped_assurance_inputs": assurance_finding_reconciliation["multiply_mapped_input_observations"],
        "reviewer_written_files": sum(row["reviewer_wrote_files"] for row in independent_reviews) + int(synthesis_review["reviewer_wrote_files"]),
        "matrix_rows_changed": projection["matrix_rows_changed"],
        "matrix_cells_changed": projection["matrix_cells_changed"],
    }
    assert verified_counts == {
        "independent_candidate_reviews": 2, "cohort_synthesis_reviews": 1, "total_fresh_semantic_reviews": 3,
        "unique_reviewed_candidates": 1, "reviewed_route_actions": 1, "owner_route_actions": 1,
        "accepted_route_records": 1, "accepted_controller_action_bridges": 1, "accepted_page_records": 0,
        "accepted_distinct_feature_ids": 1, "new_distinct_feature_ids": 0,
        "selected_controller_literal_inertia_page_callsites": 0, "existing_page_owner_context_rows": 1,
        "separate_page_route_sibling_context_rows": 1, "page_path_caller_contexts": 3,
        "selected_api_frontend_exact_caller_occurrences": 0,
        "source_packet_expansion_files": 24, "source_packet_expansion_existing_files": 6,
        "source_packet_expansion_new_files": 18, "source_packet_expansion_locus_corrections": 1,
        "independent_question_dispositions": 16, "reviewer_requested_expansion_references": 39,
        "local_assurance_observations": 17, "assurance_reconciliation_input_rows": 17,
        "deduplicated_assurance_families": 9, "shared_assurance_findings": 3,
        "assurance_evidence_records": 12, "mapped_action_assurance_outputs": 9,
        "mapped_shared_assurance_outputs": 1, "unique_mapped_assurance_outputs": 10,
        "unmapped_assurance_inputs": 0, "multiply_mapped_assurance_inputs": 0,
        "reviewer_written_files": 0, "matrix_rows_changed": 0, "matrix_cells_changed": 0,
    }
    verified_count_evidence = {
        "independent_candidate_reviews": ["/independent_candidate_reviews"],
        "cohort_synthesis_reviews": ["/synthesis_review"],
        "total_fresh_semantic_reviews": ["/independent_candidate_reviews", "/synthesis_review"],
        "unique_reviewed_candidates": ["/independent_candidate_reviews/*/candidate_id"],
        "reviewed_route_actions": ["/action_decisions"],
        "owner_route_actions": ["/action_decisions/*/outcome"],
        "accepted_route_records": ["/action_decisions/*/route_ownership_authorized"],
        "accepted_controller_action_bridges": ["/action_decisions/*/controller_action_bridge_authorized"],
        "accepted_page_records": ["/action_decisions/*/page_ownership_authorized"],
        "accepted_distinct_feature_ids": ["/action_decisions/*/candidate_feature_id"],
        "new_distinct_feature_ids": ["/feature_set_reconciliation/selected_feature_is_new_distinct_feature"],
        "selected_controller_literal_inertia_page_callsites": ["/selected_action_evidence/literal_inertia_page_callsites"],
        "existing_page_owner_context_rows": ["/page_sibling_caller_neighbor_reconciliation/existing_page_owner_context"],
        "separate_page_route_sibling_context_rows": ["/page_sibling_caller_neighbor_reconciliation/separate_page_route_sibling"],
        "page_path_caller_contexts": ["/page_sibling_caller_neighbor_reconciliation/page_path_caller_contexts"],
        "selected_api_frontend_exact_caller_occurrences": ["/tracked_caller_and_browser_scan_receipts/selected_api_exact_frontend_caller_occurrences"],
        "source_packet_expansion_files": ["/source_packet_expansion/expanded_files"],
        "source_packet_expansion_existing_files": ["/source_packet_expansion/expanded_files/*/original_packet_present"],
        "source_packet_expansion_new_files": ["/source_packet_expansion/expanded_files/*/original_packet_present"],
        "source_packet_expansion_locus_corrections": ["/source_packet_expansion/locus_corrections"],
        "independent_question_dispositions": ["/independent_candidate_reviews/*/question_dispositions"],
        "reviewer_requested_expansion_references": ["/independent_candidate_reviews/*/requested_expansion_paths"],
        "local_assurance_observations": ["/independent_candidate_reviews/*/local_assurance_observation_ids"],
        "assurance_reconciliation_input_rows": ["/assurance_finding_reconciliation/input_rows"],
        "deduplicated_assurance_families": ["/action_decisions/0/assurance_findings"],
        "shared_assurance_findings": ["/shared_assurance_findings"],
        "assurance_evidence_records": ["/action_decisions/0/assurance_findings", "/shared_assurance_findings"],
        "mapped_action_assurance_outputs": ["/assurance_finding_reconciliation/mapped_action_finding_ids"],
        "mapped_shared_assurance_outputs": ["/assurance_finding_reconciliation/mapped_shared_finding_ids"],
        "unique_mapped_assurance_outputs": ["/assurance_finding_reconciliation/mapped_action_finding_ids", "/assurance_finding_reconciliation/mapped_shared_finding_ids"],
        "unmapped_assurance_inputs": ["/assurance_finding_reconciliation/unmapped_input_observations"],
        "multiply_mapped_assurance_inputs": ["/assurance_finding_reconciliation/multiply_mapped_input_observations"],
        "reviewer_written_files": ["/independent_candidate_reviews/*/reviewer_wrote_files", "/synthesis_review/reviewer_wrote_files"],
        "matrix_rows_changed": ["/reviewed_projection_if_integrated/matrix_rows_changed"],
        "matrix_cells_changed": ["/reviewed_projection_if_integrated/matrix_cells_changed"],
    }
    assert set(verified_count_evidence) == set(verified_counts)

    credit_boundary = {
        "REVIEWED_STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
        "REVIEWED_STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
        "BOUNDED_OVERLAY_INTEGRATION_AUTHORIZED": True,
        "CURRENT_OVERLAY_OWNERSHIP_CREDIT": False,
        "PRIOR_PAGE_OWNER_CONTEXT_PRESERVED": True,
        "STATIC_PAGE_FEATURE_OWNERSHIP": False,
        "prior_page_owner_context_inherited_or_recredited": False,
        "sibling_route_context_inherited_or_recredited": False,
        "adjacent_or_next_queue_context_inherited_or_recredited": False,
        "complete_route_page_feature_crosswalk": False,
        "framework_route_reachability": False, "navigation": False, "canonical_object_ownership_correctness": False,
        "site_authorization_correctness": False, "permission_correctness": False, "privacy_correctness": False,
        "direct_object_concealment_correctness": False, "query_correctness": False, "projection_correctness": False,
        "period_correctness": False, "response_minimization_correctness": False, "lifecycle_correctness": False,
        "concurrency_and_idempotency_correctness": False, "runtime": False, "database": False, "build": False,
        "application_browser": False, "responsive_application": False, "visual_application_workflow": False,
        "executed_tests": False, "application_source_mutation": False, "matrix_mutation": False,
        "benchmark": False, "ease": False, "release": False, "pass": False, "final_finding": False,
        "completion": False, "audit_complete": False,
    }
    assert {key for key, value in credit_boundary.items() if value is True} == {
        "REVIEWED_STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
        "REVIEWED_STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
        "BOUNDED_OVERLAY_INTEGRATION_AUTHORIZED",
        "PRIOR_PAGE_OWNER_CONTEXT_PRESERVED",
    }

    verified_global_identity = {
        "reviewed_candidate_id_list_sha256": cohort["identity"]["candidate_id_list_sha256"],
        "reviewed_queue_index_list_sha256": cohort["identity"]["queue_index_list_sha256"],
        "reviewed_queue_id_list_sha256": cohort["identity"]["queue_id_list_sha256"],
        "reviewed_canonical_key_list_sha256": cohort["identity"]["canonical_key_list_sha256"],
        "reviewed_route_record_id_list_sha256": cohort["identity"]["route_record_id_list_sha256"],
        "reviewed_literal_route_name_list_sha256": cohort["identity"]["literal_route_name_list_sha256"],
        "reviewed_effective_route_name_list_sha256": cohort["identity"]["effective_route_name_list_sha256"],
        "reviewed_action_key_list_sha256": cohort["identity"]["action_key_list_sha256"],
        "reviewed_candidate_record_sha256_list_sha256": cohort["identity"]["candidate_record_sha256_list_sha256"],
        "reviewed_records_sha256": cohort["identity"]["records_sha256"],
        "source_review_packet_sha256": cohort["identity"]["source_review_packet_sha256"],
        "page_context_sha256": cohort["identity"]["page_context_sha256"],
        "excluded_adjacent_queue_record_sha256": cohort["identity"]["excluded_adjacent_queue_record_sha256"],
        "next_pending_queue_record_sha256": cohort["identity"]["next_pending_queue_record_sha256"],
        "independent_review_id_list_sha256": canonical_list_sha256([row["review_id"] for row in independent_reviews]),
        "independent_reviewer_task_path_list_sha256": canonical_list_sha256([row["reviewer_task_path"] for row in independent_reviews]),
        "independent_review_record_sha256_list_sha256": canonical_list_sha256([row["independent_review_record_sha256"] for row in independent_reviews]),
        "independent_reviews_sha256": canonical_json_sha256(independent_reviews),
        "owner_candidate_id_list_sha256": canonical_list_sha256([CANDIDATE_ID]),
        "owner_route_record_id_list_sha256": canonical_list_sha256([route["route_record_id"]]),
        "owner_source_record_key_list_sha256": canonical_list_sha256([OWNER_SOURCE_RECORD_KEY]),
        "owner_action_key_list_sha256": canonical_list_sha256([ACTION_KEY]),
        "owner_bridge_key_list_sha256": canonical_list_sha256(["|".join(BRIDGE_KEY)]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([CANDIDATE_RECORD_SHA256]),
        "owner_feature_id_list_sha256": canonical_list_sha256([FEATURE_ID]),
        "new_owner_feature_id_list_sha256": canonical_list_sha256([]),
        "decision_record_sha256_list_sha256": canonical_list_sha256([decision["decision_record_sha256"]]),
        "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
        "synthesis_record_sha256": synthesis_review["synthesis_record_sha256"],
        "source_packet_expansion_union_manifest_sha256": EXPANSION_UNION_MANIFEST_SHA256,
        "source_packet_expansions_sha256": canonical_json_sha256(expansions),
        "assurance_findings_sha256": canonical_json_sha256(ASSURANCE_FINDINGS),
        "shared_assurance_findings_sha256": canonical_json_sha256(SHARED_ASSURANCE_FINDINGS),
        "combined_assurance_findings_sha256": canonical_json_sha256([*ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]),
        "independent_question_dispositions_sha256": synthesis_review["independent_question_dispositions_sha256"],
        "independent_local_assurance_observation_ids_sha256": synthesis_review["independent_local_assurance_observation_ids_sha256"],
        "independent_requested_expansion_paths_sha256": synthesis_review["independent_requested_expansion_paths_sha256"],
        "assurance_finding_reconciliation_sha256": synthesis_review["assurance_finding_reconciliation_sha256"],
        "assurance_finding_reconciliation_input_id_list_sha256": assurance_finding_reconciliation["input_id_list_sha256"],
        "assurance_finding_reconciliation_mapping_rows_sha256": assurance_finding_reconciliation["mapping_rows_sha256"],
        "source_packet_expansion_locus_correction_records_sha256": synthesis_review["source_packet_expansion_locus_correction_records_sha256"],
        "unresolved_correctness_only_boundaries_sha256": synthesis_review["unresolved_correctness_only_boundaries_sha256"],
        "selected_action_evidence_sha256": canonical_json_sha256(selected_action_evidence),
        "feature_set_reconciliation_sha256": canonical_json_sha256(feature_set_reconciliation),
        "verified_counts_sha256": canonical_json_sha256(verified_counts),
        "verified_count_evidence_sha256": canonical_json_sha256(verified_count_evidence),
        "credit_boundary_sha256": canonical_json_sha256(credit_boundary),
        "question_dispositions_sha256": canonical_json_sha256(QUESTION_DISPOSITIONS),
    }

    return {
        "schema_version": "run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23-v1",
        "run_id": "RUN-141R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-REVIEW-WAVE-23",
        "status": "GO_TWO_BLINDED_REVIEWS_AND_DISTINCT_SYNTHESIS_COMPLETE_ONE_BOUNDED_OWNER_ZERO_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
            "mechanical_discrepancies": 0, "semantic_outcome_discrepancies": 0,
            "identity_or_key_discrepancies": 0, "page_credit_discrepancies": 0, "hard_stop_discrepancies": 0,
            "source_packet_expansion_files": 24, "source_packet_expansion_existing_files": 6, "source_packet_expansion_new_files": 18,
            "source_packet_expansion_locus_corrections": 1, "deduplicated_assurance_families": 9,
            "independent_candidate_reviews": 2, "cohort_synthesis_reviews": 1, "reviewed_route_actions": 1,
            "owner_route_actions": 1, "shared_relations": 0, "alias_or_redirect": 0, "dead_or_noncanonical": 0, "evidence_gaps": 0,
            "static_route_owner_records_authorized": 1, "static_controller_action_bridges_authorized": 1,
            "static_page_owner_records_authorized": 0, "bounded_overlay_authorized": True,
            "current_overlay_credit_awarded": False, "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False, "correctness_or_downstream_credit_authorized": False, "gate_4_complete": False,
        },
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT, "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE, "routes_tree": ROUTES_TREE, "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE, "tests_tree": TESTS_TREE,
            "cohort": COHORT_PATH.relative_to(AUDIT_DIR).as_posix(), "cohort_sha256": COHORT_SHA256,
            "cohort_generator": COHORT_GENERATOR.relative_to(AUDIT_DIR).as_posix(), "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "cohort_source_review_packet_sha256": SOURCE_PACKET_SHA256,
            "matrix": MATRIX_PATH.relative_to(AUDIT_DIR).as_posix(), "matrix_sha256": MATRIX_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(), "materializer_sha256": sha256_file(Path(__file__)),
        },
        "architecture_rule": "Oblivion Findings is one operating organisation across multiple Sites. Legacy organisation fields are storage context, not tenancy or an access boundary. Static ownership proves neither exact permission, approved-Site reach, canonical ownership, privacy, direct-object concealment, query correctness, nor lifecycle safety.",
        "methods": [
            "Two blinded reviewers independently adjudicated the same frozen name-only API action; neither wrote files.",
            "A distinct synthesizer reconciled both records, the noninheritance boundaries, and one owner outcome.",
            "A 24-file correctness-only expansion was pinned to bytes identical at HEAD and the frozen application commit.",
            "One requested locus beyond EOF was bounded to line 493 and disclosed; no outcome changed.",
            "Only later bounded route-owner and controller-action bridge integration is authorized; current overlay and every downstream credit remain zero.",
        ],
        "verified_counts": verified_counts,
        "verified_count_evidence": verified_count_evidence,
        "verified_global_identity": verified_global_identity,
        "independent_candidate_reviews": independent_reviews,
        "question_dispositions": QUESTION_DISPOSITIONS,
        "synthesis_review": synthesis_review,
        "action_decisions": action_decisions,
        "shared_assurance_findings": SHARED_ASSURANCE_FINDINGS,
        "assurance_finding_reconciliation": assurance_finding_reconciliation,
        "source_packet_expansion": {
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_known_expansion_candidates_adjudicated": False,
            "original_packet_retroactively_described_as_complete": False,
            "canonical_union_manifest_sha256": EXPANSION_UNION_MANIFEST_SHA256,
            "expanded_files": expansions,
            "all_expanded_files_match_application_commit_blobs": True,
            "locus_corrections": correction_records,
            "requested_but_not_fully_inspected": unresolved_correctness_only_boundaries,
            "expansion_authorizes_action_outcome_change": False,
            "expansion_authorizes_correctness_credit": False,
        },
        "tracked_caller_and_browser_scan_receipts": scans,
        "selected_action_evidence": selected_action_evidence,
        "feature_set_reconciliation": feature_set_reconciliation,
        "reviewed_projection_if_integrated": projection,
        "page_sibling_caller_neighbor_reconciliation": page_reconciliation,
        "credit_boundary": credit_boundary,
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
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_RELATIVE,
        "sha256": sha256_file(OUTPUT_PATH),
        "independent_candidate_reviews": payload["decision"]["independent_candidate_reviews"],
        "owner_route_actions": payload["decision"]["owner_route_actions"],
        "source_packet_expansion_files": payload["decision"]["source_packet_expansion_files"],
        "source_packet_expansion_union_manifest_sha256": payload["source_packet_expansion"]["canonical_union_manifest_sha256"],
        "current_overlay_credit_awarded": payload["decision"]["current_overlay_credit_awarded"],
        "audit_complete": payload["audit_completion_test_met"],
    }, indent=2))


if __name__ == "__main__":
    main()
