#!/usr/bin/env python3
"""Normalize RUN-010 static Inertia page/support adjudication evidence.

The generator writes only current audit artifacts.  It does not boot the
application, resolve framework routes, run a build/test/database, or use a
browser/network.
"""

from __future__ import annotations

import hashlib
import json
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
GENERATED_AT = "2026-08-24T17:07:21+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def digest(payload: object) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


PAGE_CANDIDATES = [
    {
        "path": "resources/js/pages/Governance/Components/WidgetCard.tsx",
        "classification": "dead/unreachable candidate",
        "evidence": "Default-exported WidgetCard component has no page shell and no resolved importer, backend render name, exact path reference, or route owner.",
        "closest_ownership_anchors": ["routes/governance.php:24", "app/Domain/Governance/Http/Controllers/DashboardController.php:29", "resources/js/pages/Governance/Dashboard.tsx"],
    },
    {
        "path": "resources/js/pages/catering/products/index.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "The products GET returns JSON for API use and otherwise redirects to the current meal planner; no render/import targets this legacy page.",
        "closest_ownership_anchors": ["routes/catering.php:49", "app/Http/Controllers/Catering/ProductController.php:25", "resources/js/pages/catering/meal-planner.tsx"],
    },
    {
        "path": "resources/js/pages/catering/recipes/edit.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "RecipeController states the standalone editor was folded into catering and redirects non-JSON edit requests to the meal planner.",
        "closest_ownership_anchors": ["routes/catering.php:36", "app/Http/Controllers/Catering/RecipeController.php:34-46", "resources/js/pages/sites/meal-planner/_recipe-edit-dialog.tsx"],
    },
    {
        "path": "resources/js/pages/catering/recipes/index.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "The recipes index route redirects to the meal planner and no importer or backend render targets this file.",
        "closest_ownership_anchors": ["routes/catering.php:29", "app/Http/Controllers/Catering/RecipeController.php:13-19", "app/Http/Controllers/Catering/DashboardController.php:41"],
    },
    {
        "path": "resources/js/pages/catering/recipes/show.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "The recipe show route redirects to the meal planner and no importer or backend render targets this file.",
        "closest_ownership_anchors": ["routes/catering.php:34", "app/Http/Controllers/Catering/RecipeController.php:22-25", "resources/js/pages/sites/meal-planner/index.tsx"],
    },
    {
        "path": "resources/js/pages/catering/tags/index.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "DietaryTagController says the page was folded into Meal Planner; non-JSON GET redirects while JSON remains for the embedded manager.",
        "closest_ownership_anchors": ["routes/catering.php:62", "app/Http/Controllers/Catering/DietaryTagController.php:12-16", "resources/js/pages/sites/meal-planner/_library-dialogs.tsx"],
    },
    {
        "path": "resources/js/pages/clients/assignments.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "Both client route families render operations/clients/assignments; no importer or render targets this older duplicate.",
        "closest_ownership_anchors": ["routes/clients.php:174", "routes/operations.php:368", "app/Http/Controllers/ClientAssignmentController.php:38", "resources/js/pages/operations/clients/assignments.tsx"],
    },
    {
        "path": "resources/js/pages/clients/create.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "Both client create URLs are owned by ClientController::create and render operations/clients/create.",
        "closest_ownership_anchors": ["routes/clients.php:90", "routes/operations.php:236", "app/Http/Controllers/ClientController.php:2413-2420", "resources/js/pages/operations/clients/create.tsx"],
    },
    {
        "path": "resources/js/pages/clients/documents.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "ClientDocumentController renders operations/clients/documents; no importer or render targets this duplicate.",
        "closest_ownership_anchors": ["routes/clients.php:34", "routes/operations.php:148", "app/Http/Controllers/ClientDocumentController.php:54", "resources/js/pages/operations/clients/documents.tsx"],
    },
    {
        "path": "resources/js/pages/clients/edit.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "ClientController's contract says no standalone edit Inertia page exists; it returns modal JSON or redirects to the canonical client surface.",
        "closest_ownership_anchors": ["routes/clients.php:105", "routes/operations.php:253", "app/Http/Controllers/ClientController.php:2632-2726", "resources/js/pages/operations/clients/_create-dialog.tsx"],
    },
    {
        "path": "resources/js/pages/clients/incidents.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "ClientIncidentController renders operations/clients/incidents; no importer or render targets this duplicate.",
        "closest_ownership_anchors": ["routes/clients.php:221", "routes/operations.php:594", "app/Http/Controllers/ClientIncidentController.php:44", "resources/js/pages/operations/clients/incidents.tsx"],
    },
    {
        "path": "resources/js/pages/clients/index.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "ClientController::index renders operations/clients/index; no importer or render targets this duplicate.",
        "closest_ownership_anchors": ["routes/clients.php:31", "routes/operations.php:110", "app/Http/Controllers/ClientController.php:241", "resources/js/pages/operations/clients/index.tsx"],
    },
    {
        "path": "resources/js/pages/clients/medical-simple.tsx",
        "classification": "test/demo/story",
        "evidence": "The page labels itself Medical Profile (Debug); no route/controller renders it and the current medical GET redirects to eMAR.",
        "closest_ownership_anchors": ["resources/js/pages/clients/medical-simple.tsx:43", "app/Http/Controllers/ClientMedicalController.php:89-93"],
    },
    {
        "path": "resources/js/pages/clients/medical.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "The exact file is read only by a source test and is not imported/rendered; both medical route families redirect through EmarUrl::medications.",
        "closest_ownership_anchors": ["routes/clients.php:42", "routes/operations.php:167", "app/Http/Controllers/ClientMedicalController.php:93", "app/Support/EmarUrl.php"],
    },
    {
        "path": "resources/js/pages/clients/portal-users.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "ClientPortalUserController renders operations/clients/portal-users; no importer or render targets this duplicate.",
        "closest_ownership_anchors": ["routes/clients.php:148", "routes/operations.php:334", "app/Http/Controllers/ClientPortalUserController.php:26", "resources/js/pages/operations/clients/portal-users.tsx"],
    },
    {
        "path": "resources/js/pages/clients/risks.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "ClientRiskController renders operations/clients/risks; no importer or render targets this duplicate.",
        "closest_ownership_anchors": ["routes/clients.php:243", "routes/operations.php:615", "app/Http/Controllers/ClientRiskController.php:26", "resources/js/pages/operations/clients/risks.tsx"],
    },
    {
        "path": "resources/js/pages/emar/Placeholder.tsx",
        "classification": "dead/unreachable candidate",
        "evidence": "Generic Coming Soon component has no importer, backend render, or exact reference; current eMAR routes render concrete pages.",
        "closest_ownership_anchors": ["routes/emar.php:59", "app/Http/Controllers/Emar/EmarController.php:796", "resources/js/pages/emar/Index.tsx"],
    },
    {
        "path": "resources/js/pages/integrations/unifi.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "Legacy integration URLs permanently redirect to Security Devices, whose controller renders security-devices/integrations/unifi.",
        "closest_ownership_anchors": ["routes/integrations.php:15", "routes/settings.php:358", "routes/security-devices.php:511", "app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:185", "resources/js/pages/security-devices/integrations/unifi.tsx"],
    },
    {
        "path": "resources/js/pages/operations/clients/medical.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "The file is read only by a source test and is not imported/rendered; the operations medical GET redirects to the canonical eMAR URL.",
        "closest_ownership_anchors": ["routes/operations.php:167", "app/Http/Controllers/ClientMedicalController.php:93", "app/Support/EmarUrl.php", "resources/js/pages/emar/Medications.tsx"],
    },
    {
        "path": "resources/js/pages/portal/messages/show.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "The live show route remains, but PortalMessageController renders the unified portal/messages page with active-conversation props.",
        "closest_ownership_anchors": ["routes/portal.php:152", "app/Http/Controllers/Portal/PortalMessageController.php:88", "app/Http/Controllers/Portal/PortalMessageController.php:206", "resources/js/pages/portal/messages.tsx"],
    },
    {
        "path": "resources/js/pages/settings/sso.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "GET /settings/sso renders settings/sso-config; group mapping separately renders settings/sso-groups.",
        "closest_ownership_anchors": ["routes/settings.php:282", "app/Http/Controllers/Settings/SsoConfigController.php:15", "app/Http/Controllers/Settings/SsoGroupController.php:23", "resources/js/pages/settings/sso-config.tsx"],
    },
    {
        "path": "resources/js/pages/sites/_readiness-panel.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "Named support export has no importer/render; the current Site profile imports and renders tabs/readiness.",
        "closest_ownership_anchors": ["routes/assets.php:35", "app/Http/Controllers/Sites/SiteProfileController.php:40", "resources/js/pages/sites/show.tsx:69", "resources/js/pages/sites/tabs/readiness.tsx"],
    },
    {
        "path": "resources/js/pages/sites/capacity/Index.tsx",
        "classification": "dead/unreachable candidate",
        "evidence": "No capacity route or controller render exists; capacity is exposed in the current Sites index and unified Site profile.",
        "closest_ownership_anchors": ["routes/assets.php:34-35", "app/Http/Controllers/Sites/SiteProfileController.php:40", "resources/js/pages/sites/index.tsx:1719", "resources/js/pages/sites/show.tsx"],
    },
    {
        "path": "resources/js/pages/system/users/Index.tsx",
        "classification": "alias/generated/legacy",
        "evidence": "System UsersController::index renders settings/users/index; create and show have separate current ownership.",
        "closest_ownership_anchors": ["routes/system.php:64", "app/Http/Controllers/System/UsersController.php:135", "resources/js/pages/settings/users/index.tsx"],
    },
    {
        "path": "resources/js/pages/welcome.tsx",
        "classification": "test/demo/story",
        "evidence": "Stock Laravel starter content has no importer or backend render; the public root route renders home.",
        "closest_ownership_anchors": ["routes/web.php:68-69", "resources/js/pages/home.tsx"],
    },
]
for row in PAGE_CANDIDATES:
    row["confidence"] = "high"

PROMPT_CLASSIFICATIONS = {
    "resources/js/pages/catering/products/index.tsx": "Redirect/legacy",
    "resources/js/pages/catering/recipes/edit.tsx": "Redirect/legacy",
    "resources/js/pages/catering/recipes/index.tsx": "Redirect/legacy",
    "resources/js/pages/catering/recipes/show.tsx": "Redirect/legacy",
    "resources/js/pages/catering/tags/index.tsx": "Redirect/legacy",
    "resources/js/pages/clients/assignments.tsx": "Duplicate",
    "resources/js/pages/clients/create.tsx": "Duplicate",
    "resources/js/pages/clients/documents.tsx": "Duplicate",
    "resources/js/pages/clients/edit.tsx": "Redirect/legacy",
    "resources/js/pages/clients/incidents.tsx": "Duplicate",
    "resources/js/pages/clients/index.tsx": "Duplicate",
    "resources/js/pages/clients/medical.tsx": "Redirect/legacy",
    "resources/js/pages/clients/portal-users.tsx": "Duplicate",
    "resources/js/pages/clients/risks.tsx": "Duplicate",
    "resources/js/pages/integrations/unifi.tsx": "Redirect/legacy",
    "resources/js/pages/operations/clients/medical.tsx": "Redirect/legacy",
    "resources/js/pages/portal/messages/show.tsx": "Redirect/legacy",
    "resources/js/pages/settings/sso.tsx": "Duplicate",
    "resources/js/pages/sites/_readiness-panel.tsx": "Duplicate",
    "resources/js/pages/system/users/Index.tsx": "Duplicate",
}
for row in PAGE_CANDIDATES:
    if row["classification"] == "dead/unreachable candidate":
        row["prompt_classification"] = "Dead/unreachable"
    elif row["classification"] == "test/demo/story":
        row["prompt_classification"] = "Out of product scope"
    else:
        row["prompt_classification"] = PROMPT_CLASSIFICATIONS[row["path"]]


MISSING_RENDER_TARGETS = [
    {
        "target": "hr/recruitment/jobs",
        "render_call": "app/Http/Controllers/Hr/RecruitmentJobController.php:95",
        "classification": "retired_unreachable_render_literal",
        "route_evidence": "GET /hr/recruitment/jobs is a closure redirect to hr/recruitment/index?tab=requisitions; the controller index is not routed.",
        "canonical_owner": "app/Http/Controllers/Hr/RecruitmentController.php:65 -> resources/js/pages/hr/recruitment/index.tsx",
    },
    {
        "target": "hr/recruitment/kits",
        "render_call": "app/Http/Controllers/Hr/InterviewKitController.php:32",
        "classification": "retired_unreachable_render_literal",
        "route_evidence": "GET /hr/recruitment/kits is a closure redirect to hr/recruitment/index?tab=kits; the controller index is not routed.",
        "canonical_owner": "app/Http/Controllers/Hr/RecruitmentController.php:65 -> resources/js/pages/hr/recruitment/index.tsx",
    },
    {
        "target": "hr/training/index",
        "render_call": "app/Http/Controllers/Hr/TrainingDashboardController.php:139",
        "classification": "retired_unreachable_render_literal",
        "route_evidence": "No route references TrainingDashboardController; current training URLs use TrainingController::catalog.",
        "canonical_owner": "app/Http/Controllers/Hr/TrainingController.php:118 -> resources/js/pages/hr/training/catalog.tsx",
    },
    {
        "target": "operations/timesheets/approvals",
        "render_call": "app/Http/Controllers/TimesheetController.php:47",
        "classification": "retired_unreachable_render_literal",
        "route_evidence": "The GET route redirects to operations/timesheets/index?tab=submitted; TimesheetController::approvals is not routed.",
        "canonical_owner": "app/Http/Controllers/TimesheetController.php:347 -> resources/js/pages/operations/timesheets/index.tsx",
    },
    {
        "target": "training/competencies/index",
        "render_call": "app/Http/Controllers/Training/CompetencyAssessmentController.php:10",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references CompetencyAssessmentController; current competency routes use HR competency owners.",
        "canonical_owner": "app/Http/Controllers/Hr/CompetencyController.php:43 -> resources/js/pages/hr/performance/competencies/index.tsx",
    },
    {
        "target": "training/competencies/show",
        "render_call": "app/Http/Controllers/Training/CompetencyAssessmentController.php:11",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references CompetencyAssessmentController::show.",
        "canonical_owner": "routes/hr.php:950-963 and app/Http/Controllers/Hr/CompetencyController.php",
    },
    {
        "target": "training/inductions/index",
        "render_call": "app/Http/Controllers/Training/InductionController.php:10",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references InductionController; current induction routes use StaffInductionController.",
        "canonical_owner": "routes/training.php:87-97 and app/Http/Controllers/Training/StaffInductionController.php",
    },
    {
        "target": "training/inductions/show",
        "render_call": "app/Http/Controllers/Training/InductionController.php:11",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references InductionController::show; the current staff induction surface redirects to HR onboarding.",
        "canonical_owner": "app/Http/Controllers/Training/StaffInductionController.php -> hr.onboarding.index",
    },
    {
        "target": "training/records/index",
        "render_call": "app/Http/Controllers/Training/TrainingRecordController.php:10",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references TrainingRecordController; current record presentation is consolidated under HR training.",
        "canonical_owner": "app/Http/Controllers/Hr/TrainingController.php:118 -> resources/js/pages/hr/training/catalog.tsx",
    },
    {
        "target": "training/records/user",
        "render_call": "app/Http/Controllers/Training/TrainingRecordController.php:11",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references TrainingRecordController::userRecords.",
        "canonical_owner": "NOT_EXACTLY_ESTABLISHED; nearest current surface: routes/hr.php:357,1392 -> app/Http/Controllers/Hr/TrainingController.php:118 -> resources/js/pages/hr/training/catalog.tsx",
    },
    {
        "target": "training/records/show",
        "render_call": "app/Http/Controllers/Training/TrainingRecordController.php:12",
        "classification": "unrouted_stub_render_literal",
        "route_evidence": "No route references TrainingRecordController::show.",
        "canonical_owner": "NOT_EXACTLY_ESTABLISHED; nearest current surface: routes/hr.php:357,1392 -> app/Http/Controllers/Hr/TrainingController.php:118 -> resources/js/pages/hr/training/catalog.tsx",
    },
]
for row in MISSING_RENDER_TARGETS:
    row["file_exists"] = False
    row["confidence"] = "high"


assert len(PAGE_CANDIDATES) == 25
assert Counter(row["classification"] for row in PAGE_CANDIDATES) == {
    "alias/generated/legacy": 20,
    "dead/unreachable candidate": 3,
    "test/demo/story": 2,
}
assert Counter(row["prompt_classification"] for row in PAGE_CANDIDATES) == {
    "Redirect/legacy": 10,
    "Duplicate": 10,
    "Dead/unreachable": 3,
    "Out of product scope": 2,
}
assert len(MISSING_RENDER_TARGETS) == 11
assert Counter(row["classification"] for row in MISSING_RENDER_TARGETS) == {
    "unrouted_stub_render_literal": 7,
    "retired_unreachable_render_literal": 4,
}


PAGE_PAYLOAD = {
    "schema_version": 1,
    "status": "COMPLETE_STATIC_SOURCE_ADJUDICATION_NO_RUNTIME_CREDIT",
    "generated_at": GENERATED_AT,
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "resources_js_tree": "1671a7551c004571c48bb00c34522928e6f1f173",
        "app_tree": "92c8425a7cb15a92609c69a8c2f26bbda4f178b7",
        "routes_tree": "9b7f78510d970db64ea3a6540e8a36b8700bf272",
        "current_subtrees_match_pin": True,
    },
    "methods": [
        "Reproduced the non-test resources/js/pages/**/*.tsx Inertia glob.",
        "Resolved static import, export-from, dynamic-import, relative, alias, extension, and index references for the candidate partition; no global JS/TS file-count denominator is credited.",
        "Scanned committed PHP for executable literal Inertia::render(...) and inertia(...) calls and excluded the commented ClientController call at line 2408.",
        "Reconciled exact path literals, component exports, controller methods, route registrations, redirects, canonical replacements, and source-only tests.",
        "Performed no application boot, test, build, database, network, or browser work.",
    ],
    "reproduction": {
        "resolver_non_test_tsx": 963,
        "php_render_callsites": 745,
        "nonliteral_php_render_callsites": 0,
        "unique_backend_render_names": 722,
        "existing_backend_render_roots": 711,
        "missing_backend_render_targets": 11,
        "resolver_partition": {"matched_backend_render_roots": 711, "unrendered_imported": 227, "unrendered_unimported": 25, "sum": 963},
        "matches_prior_census": True,
    },
    "candidate_adjudication": PAGE_CANDIDATES,
    "candidate_class_counts": {
        "page root": 0,
        "imported support/component": 0,
        "test/demo/story": 2,
        "dead/unreachable candidate": 3,
        "alias/generated/legacy": 20,
        "unresolved": 0,
        "total": 25,
    },
    "prompt_class_counts_for_25_candidates": {
        "Redirect/legacy": 10,
        "Duplicate": 10,
        "Dead/unreachable": 3,
        "Out of product scope": 2,
    },
    "missing_render_target_adjudication": MISSING_RENDER_TARGETS,
    "recommended_denominator": {
        "name": "current static file-backed Inertia page-root denominator",
        "value": 711,
        "basis": "All 963 resolver TSX paths are partitioned for static render/import identity: 711 existing literal backend render roots, 227 imported support paths, and none of the 25 unrendered/unimported candidates is a page root. Eleven missing render names occur in retired or unrouted methods and have no page file.",
        "backend_target_liability_count": 11,
        "backend_target_liability_credit": 0,
        "limits": "Committed-source denominator only; final prompt classification of the 711 roots, framework route reachability, browser, build, runtime, deployment, release, and FEATURE-ID mapping remain open.",
    },
    "root_reconciliation_corrections": [
        "RUN-010 reported an enumeration of 3,392 JS/TS source files. RUN-011 could not reproduce that number, so it is quarantined and supplies no denominator or completion credit.",
        "The training/records/user and training/records/show replacement owner was not exactly established; only exact nearest-current-surface anchors are retained.",
        "Internal candidate classes are supplemented by the governing prompt taxonomy. This wave does not classify all 711 roots under that taxonomy.",
    ],
    "execution_credit": {"application_runtime": 0, "tests": 0, "build": 0, "database": 0, "network": 0, "browser": 0},
    "evidence_count": 36,
    "evidence_count_semantics": "25 candidate adjudications plus 11 missing-render-target adjudications",
    "completion_test_met": True,
    "wrote_files": False,
}


ASSIGNMENT = {
    "assignment_id": "RUN-010",
    "agent_task_path": "/root/page_candidate_adjudication",
    "role": "Inertia page/support candidate adjudicator",
    "repository": "oblivionfindings workspace; application commit pin controls source identity",
    "application_commit": APPLICATION_COMMIT,
    "architecture_rule": "Single tenant, multiple Sites; page identity does not relax roles/action authority, approved-Site scope, canonical ownership, concealed direct IDs, or privacy.",
    "scope": "All 25 unrendered/unimported resolver candidates plus all 11 missing backend render targets",
    "pass_lens": "Pass 1 page reachability and denominator adjudication",
    "evidence_schema": "Pins, reproduction counts, per-path classification/evidence/anchors/confidence, denominator recommendation, execution credit, completion test, and write attestation",
    "benchmark_subset": None,
    "no_write_rule": "Return structured evidence in the agent message; do not edit repository files.",
    "completion_test": "Classify every one of 25 candidates and every one of 11 missing render targets with exact current-source ownership evidence and no unresolved rows.",
    "return_status": "COMPLETE_STATIC_SOURCE_ADJUDICATION_NO_RUNTIME_CREDIT",
    "evidence_count": 36,
    "evidence_count_basis": "25 candidate adjudications plus 11 missing-render-target adjudications.",
    "observed_audit_head": "NOT_REPORTED; pinned application subtrees matched the source commit",
    "completion_test_met": True,
    "wrote_files": False,
    "runtime_gates": None,
    "unresolved_gaps": "Framework-expanded route reachability, route-to-page-to-FEATURE-ID mapping, build resolution, browser observation, deployment identity, and release evidence remain open.",
    "root_reconciliation": "Accept 711 as the committed-source file-backed Inertia page-root denominator only; retain 11 missing literals as backend liabilities with zero page/runtime credit; quarantine the unreproduced 3,392 count and keep the final prompt page-classification gate open.",
}
ASSIGNMENT["normalized_payload_sha256"] = digest(ASSIGNMENT)

ASSIGNMENT_011 = {
    "assignment_id": "RUN-011",
    "agent_task_path": "/root/benchmark_integration_review",
    "role": "Independent page-denominator reconciliation reviewer",
    "repository": "oblivionfindings workspace; application commit pin controls source identity",
    "application_commit": APPLICATION_COMMIT,
    "architecture_rule": "Single tenant, multiple Sites; page identity does not relax roles/action authority, approved-Site scope, canonical ownership, concealed direct IDs, or privacy.",
    "scope": "Adversarially reconcile all 25 candidate rows, 11 missing-render liabilities, public summaries, generator output, agent register, and dashboard wording",
    "pass_lens": "Pass 1 independent denominator and evidence-quality challenge; not final Pass 8",
    "evidence_schema": "Pins, exact arithmetic, per-defect anchors, prompt taxonomy, credit boundary, evidence count/basis, unresolved gaps, GO/NO-GO, completion test, and write attestation",
    "benchmark_subset": None,
    "no_write_rule": "Return structured evidence in the agent message; do not edit repository files.",
    "completion_test": "Verify 25/25 candidate and 11/11 liability representation, 711+227+25=963, 20+3+2=25, prompt-taxonomy arithmetic, zero candidate roots, exact ownership wording, and zero runtime/build/browser/application credit.",
    "return_status": "GO_AFTER_BOUNDED_CORRECTION_FOLLOWUP",
    "evidence_count": 54,
    "evidence_count_basis": "25 candidate adjudications plus 11 liabilities plus eight artifact-consistency surfaces plus ten cross-cutting controls.",
    "observed_audit_head": "201ccff705669c61dcb7dcafbcfd36b725b98546",
    "initial_no_go": {
        "completion_test_met": False,
        "evidence_count": 49,
        "defects": [
            "RUN-010's 3,392 JS/TS count was not reproducible.",
            "Two training-record replacement-owner descriptions were not exact anchors.",
            "The 25 candidate rows lacked a separate governing-prompt taxonomy.",
            "The phrase 100% source-classified could be confused with the prompt's final page gate.",
            "The executive summary described 227 paths as all remaining non-roots rather than 227 of 252.",
        ],
    },
    "corrected_followup": {
        "verdict": "GO",
        "completion_test_met": True,
        "remaining_mismatches_or_overclaims": [],
    },
    "completion_test_met": True,
    "wrote_files": False,
    "runtime_gates": None,
    "unresolved_gaps": "Final prompt classification of 711 roots, framework reachability, route/page-to-FEATURE-ID mapping, build resolution, current-build application browser proof, deployment/release identity, fresh Pass 8, and final all-results/no-live-agent reconciliation remain open.",
    "root_reconciliation": "GO for the bounded static page batch after the five evidence corrections; 711 is source-only and no broader completion credit is awarded.",
}
ASSIGNMENT_011["normalized_payload_sha256"] = digest(ASSIGNMENT_011)


AGENT_PAYLOAD = {
    "schema_version": 1,
    "status": "FORMAL_PAGE_ADJUDICATION_WAVE_01_RECONCILED_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "application_commit": APPLICATION_COMMIT,
    "writer_boundary": "Only the root orchestrator wrote audit artifacts; RUN-010 returned evidence in a message and reported wrote_files=false.",
    "wave_formal_assignments_eligible": 2,
    "cumulative_formal_assignments_eligible": 11,
    "literal_prompt_minimum": 8,
    "literal_prompt_minimum_met": True,
    "planned_formal_assignments_target": 11,
    "planned_target_met": True,
    "all_returned": True,
    "all_completion_tests_met": True,
    "all_reported_no_writes": True,
    "assignment_returns": [ASSIGNMENT, ASSIGNMENT_011],
    "contradictions_and_reconciliation": ["RUN-011 initially returned NO-GO with five bounded evidence defects; the root corrected those defects and the same independent reviewer returned a replacement GO with completion_test_met=true."],
    "outstanding_required_roles_or_waves": ["canonical route/page-to-FEATURE-ID reconciliation", "final prompt classification of 711 roots", "fresh Pass 8 cross-reviewers", "final all-results-represented and no-live-agent reconciliation"],
    "live_agent_finalization_state": "RUN-010 and corrected RUN-011 returned; planned assignment target is met, but the audit remains active and fresh Pass 8 has not run.",
    "finalization_gate": False,
}


def main() -> None:
    write_json("evidence/source/current-page-adjudication-wave-01.json", PAGE_PAYLOAD)
    write_json("evidence/source/current-page-agent-register.json", AGENT_PAYLOAD)


if __name__ == "__main__":
    main()
