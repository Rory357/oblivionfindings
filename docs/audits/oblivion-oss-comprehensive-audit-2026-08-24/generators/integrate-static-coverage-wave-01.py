#!/usr/bin/env python3
"""Normalize RUN-012 through RUN-014 into audit-only static evidence."""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
GENERATED_AT = "2026-08-24T17:43:00+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_INPUT_COMMIT = "779fdea9d24b444738396698c2b9001c686ba144"


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def write_csv(relative: str, fields: list[str], rows: list[dict]) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def digest(value: object) -> str:
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


ROUTE_ROWS = [
    ("routes/api-hr.php", 14, 16, "C", "HR and IT API"),
    ("routes/api_medications.php", 30, 30, "R", "eMAR API"),
    ("routes/assets.php", 48, 48, "C", "Fleet/Assets and Sites"),
    ("routes/auth.php", 5, 5, "R", "Personal account/OAuth"),
    ("routes/catering.php", 17, 18, "M/P", "Catering and meal libraries"),
    ("routes/channels.php", 0, 0, "R", "Fleet realtime authorization; two broadcast channels"),
    ("routes/clients.php", 59, 59, "R", "Client record and care"),
    ("routes/compliance.php", 1, 1, "M/P", "Cross-module compliance command centre"),
    ("routes/console.php", 0, 2, "C", "Cross-module scheduled automation; 117 schedules and one Artisan closure"),
    ("routes/control-room.php", 115, 115, "R", "Control Room"),
    ("routes/emar.php", 122, 122, "R", "eMAR"),
    ("routes/finance.php", 272, 272, "R", "Finance"),
    ("routes/fleet-assets.php", 148, 148, "R", "Fleet & Assets"),
    ("routes/fleet.php", 10, 10, "A", "Fleet compatibility and map usage"),
    ("routes/governance.php", 190, 191, "R", "Governance"),
    ("routes/health-clinical.php", 35, 40, "R", "Care & Clinical"),
    ("routes/health-safety.php", 189, 200, "R", "Health & Safety"),
    ("routes/hr.php", 610, 639, "R", "HR"),
    ("routes/incidents.php", 29, 29, "R", "Incidents"),
    ("routes/integrations.php", 3, 3, "R", "Inbound integrations"),
    ("routes/medications.php", 8, 8, "A", "Medication compatibility/report routes"),
    ("routes/monitoring-collector.php", 4, 5, "R", "Site-scoped monitoring collector"),
    ("routes/operations.php", 346, 341, "R", "Operations and Workforce"),
    ("routes/portal.php", 85, 82, "M/P", "Client/family Portal and notification inbox"),
    ("routes/privacy.php", 49, 49, "R", "Privacy"),
    ("routes/reports.php", 10, 10, "M/P", "Cross-module report hub"),
    ("routes/respite.php", 111, 111, "M/P", "Respite"),
    ("routes/roadmap.php", 26, 27, "M/P", "Roadmap"),
    ("routes/safeguarding.php", 26, 26, "R", "Safeguarding"),
    ("routes/security-devices.php", 155, 155, "R", "Security Devices"),
    ("routes/settings.php", 102, 101, "R", "Settings"),
    ("routes/shifts.php", 11, 9, "A", "Attendance/Workforce compatibility"),
    ("routes/sites.php", 192, 192, "C", "Sites plus meal submodule"),
    ("routes/staff.php", 13, 13, "C", "HR/Workforce"),
    ("routes/system.php", 21, 21, "A", "Settings/access compatibility"),
    ("routes/tasks.php", 9, 9, "R", "All Tasks"),
    ("routes/training.php", 26, 20, "A", "HR training compatibility"),
    ("routes/web.php", 126, 118, "C", "Public, dashboards, frontline, IT, notifications, quality"),
]
assert len(ROUTE_ROWS) == 38
assert sum(row[1] for row in ROUTE_ROWS) == 3217
assert Counter(row[3] for row in ROUTE_ROWS) == {"R": 21, "A": 5, "C": 6, "M/P": 6}


ADDITIONS = [
    {
        "candidate_id": "CAP-PLAT-ADMIN-DASHBOARD",
        "module": "Platform Dashboards",
        "feature_class": "H",
        "user_job": "Review the authorised organisation and Site operational overview",
        "canonical_owner": "DashboardController",
        "production_anchors": ["routes/web.php:128", "app/Http/Controllers/DashboardController.php:24-480"],
        "representative_test_anchors": ["tests/Feature/DashboardControllerTest.php"],
        "site_role_privacy_note": "Require the exact dashboard authority and approved-Site scope; global Site visibility never replaces action authority.",
    },
    {
        "candidate_id": "CAP-DAY-TODAY-OPERATIONS-OVERVIEW",
        "module": "Frontline Workspaces",
        "feature_class": "H",
        "user_job": "Review today's authorised shifts, medication work, and attention items",
        "canonical_owner": "TodayDashboardController",
        "production_anchors": ["routes/web.php:129", "app/Http/Controllers/TodayDashboardController.php:13-124"],
        "representative_test_anchors": ["tests/Browser/Pages/TodayTest.php"],
        "site_role_privacy_note": "Show only approved Sites and authorised Clients or assigned work; the aggregate remains a read lens over canonical owners.",
    },
    {
        "candidate_id": "CAP-NOTIF-PERSONAL-INBOX-ACK",
        "module": "Notifications",
        "feature_class": "H",
        "user_job": "Read, mark, and acknowledge owned notifications",
        "canonical_owner": "NotificationInboxController and the canonical user notification relation",
        "production_anchors": ["routes/portal.php:181-231", "app/Http/Controllers/NotificationInboxController.php:9-45"],
        "representative_test_anchors": ["tests/Browser/Notifications/NotificationsTest.php"],
        "site_role_privacy_note": "Query through canonical user ownership and conceal another user's notification direct ID.",
    },
    {
        "candidate_id": "CAP-SITE-MEAL-PLANNER-RECIPE-INVENTORY",
        "module": "Catering",
        "feature_class": "H",
        "user_job": "Plan meals and govern recipes, restrictions, inventory, and shopping",
        "canonical_owner": "Site meal-planning and Catering domain owners",
        "production_anchors": ["routes/catering.php:20-72", "routes/sites.php:295-421", "app/Http/Controllers/Catering/DashboardController.php:41"],
        "representative_test_anchors": ["tests/Feature/Catering/SiteMealPlannerSiteAccessTest.php", "tests/Feature/Catering/SiteMealPlanScopeIntegrityTest.php"],
        "site_role_privacy_note": "Require approved Site plus the exact meal action; clinical restriction author and approval authority remain separate.",
    },
    {
        "candidate_id": "CAP-COMP-EXCEPTION-COMMAND-CENTRE",
        "module": "Compliance",
        "feature_class": "H",
        "user_job": "Review Site-scoped compliance exceptions, due work, and triage",
        "canonical_owner": "ComplianceDashboardController and ComplianceMetricsService",
        "production_anchors": ["routes/compliance.php:15-18", "app/Http/Controllers/Compliance/ComplianceDashboardController.php:24-89"],
        "representative_test_anchors": ["tests/Feature/Compliance/ComplianceDashboardTest.php", "tests/Feature/Compliance/ComplianceDashboardSiteScopeTest.php"],
        "site_role_privacy_note": "Require compliance.view and accessible staff/incident scope; manage and triage actions retain separate permissions.",
    },
    {
        "candidate_id": "CAP-PORT-CLIENT-FAMILY-WORKSPACE",
        "module": "Portal",
        "feature_class": "H",
        "user_job": "Use the authorised client or family dashboard, timeline, calendar, documents, photos, health, and location",
        "canonical_owner": "PortalClientController and FamilyDashboardController read projections",
        "production_anchors": ["routes/portal.php:49-180", "routes/portal.php:232-302", "app/Http/Controllers/PortalClientController.php:147", "app/Http/Controllers/Portal/FamilyDashboardController.php:371"],
        "representative_test_anchors": ["tests/Feature/Portal/PortalSurfaceTest.php", "tests/Feature/Portal/PortalDirectRouteAuthorizationTest.php"],
        "site_role_privacy_note": "Bind the canonical portal-user/client relationship, consent and privacy rules, and conceal unrelated client IDs.",
    },
    {
        "candidate_id": "CAP-PORT-FAMILY-MESSAGING-NOTES",
        "module": "Portal",
        "feature_class": "H",
        "user_job": "Exchange authorised portal messages and family notes",
        "canonical_owner": "PortalMessageController and PortalFamilyNoteController",
        "production_anchors": ["app/Http/Controllers/Portal/PortalMessageController.php", "app/Http/Controllers/Portal/PortalFamilyNoteController.php"],
        "representative_test_anchors": ["tests/Feature/Portal/PortalMessageMediaSecurityTest.php", "tests/Feature/Operations/ClientFamilyCommunicationSecurityTest.php"],
        "site_role_privacy_note": "Check conversation membership and the client relationship before media or message disclosure.",
    },
    {
        "candidate_id": "CAP-RESP-REFERRAL-REQUEST-BOOKING",
        "module": "Respite",
        "feature_class": "H",
        "user_job": "Intake, review, approve, and book respite",
        "canonical_owner": "Respite referral, request, and booking controllers",
        "production_anchors": ["routes/respite.php:20-77"],
        "representative_test_anchors": ["tests/Feature/Respite/RespiteIntakeTest.php", "tests/Feature/Respite/RespiteScopeIntegrityTest.php"],
        "site_role_privacy_note": "Require exact action authority and the canonical Site, Client, request, and booking chain.",
    },
    {
        "candidate_id": "CAP-RESP-STAY-CARE-DISCHARGE",
        "module": "Respite",
        "feature_class": "H",
        "user_job": "Check in, document daily care and risk, extend, and discharge a respite stay",
        "canonical_owner": "Respite stay, daily-note, and risk-plan owners",
        "production_anchors": ["routes/respite.php:80-95", "routes/respite.php:196-227"],
        "representative_test_anchors": ["tests/Feature/Respite/RespiteAdmissionSafetyTest.php", "tests/Feature/Respite/RespiteStateTransitionIntegrityTest.php"],
        "site_role_privacy_note": "Require approved Site and Client scope, canonical booking/stay ownership, and concealed direct IDs.",
    },
    {
        "candidate_id": "CAP-RESP-PROCEDURE-TASK-HANDOVER",
        "module": "Respite",
        "feature_class": "H",
        "user_job": "Run respite procedures, tasks, communications, and handovers",
        "canonical_owner": "Respite procedure, task, communication, and handover controllers",
        "production_anchors": ["routes/respite.php:107-175"],
        "representative_test_anchors": ["tests/Feature/Respite/RespiteActionsTest.php", "tests/Feature/Respite/RespiteNzWorkflowCompletionTest.php"],
        "site_role_privacy_note": "Keep view, manage, and approve permissions distinct; Site scope does not grant transitions.",
    },
    {
        "candidate_id": "CAP-RESP-EVIDENCE-PACK-EXPORT",
        "module": "Respite",
        "feature_class": "D",
        "user_job": "Assemble, seal, and export governed respite evidence",
        "canonical_owner": "RespiteEvidencePackController",
        "production_anchors": ["routes/respite.php:178-194", "app/Http/Controllers/Respite/RespiteEvidencePackController.php"],
        "representative_test_anchors": ["tests/Feature/Respite/RespiteScopeIntegrityTest.php", "tests/Feature/Respite/RespiteNzWorkflowCompletionTest.php"],
        "site_role_privacy_note": "Require exact evidence view, manage, and seal authority, canonical stay binding, and an immutable sealed digest.",
    },
    {
        "candidate_id": "CAP-ROAD-INITIATIVE-QUARTERLY-DECISION",
        "module": "Roadmap",
        "feature_class": "H",
        "user_job": "Manage suggestions, initiatives, quarterly plans, decisions, and approvals",
        "canonical_owner": "Roadmap domain controllers and services",
        "production_anchors": ["routes/roadmap.php:1-97", "app/Domain/Roadmap/Http/Controllers/RoadmapDashboardController.php"],
        "representative_test_anchors": ["tests/Feature/Roadmap/RoadmapWorkflowTest.php", "tests/Feature/Roadmap/RoadmapPermissionsTest.php"],
        "site_role_privacy_note": "Use exact role/action permissions, with Site/object scope only where the records carry it.",
    },
    {
        "candidate_id": "CAP-REP-CROSS-MODULE-REPORT-HUB",
        "module": "Reporting",
        "feature_class": "H",
        "user_job": "Browse authorised report families and combined summaries",
        "canonical_owner": "ReportsController, CombinedReportController, and ModuleReportController",
        "production_anchors": ["routes/reports.php:1-62", "app/Http/Controllers/ReportsController.php", "app/Http/Controllers/CombinedReportController.php", "app/Http/Controllers/ModuleReportController.php"],
        "representative_test_anchors": ["tests/Browser/Reports/ReportsTest.php"],
        "site_role_privacy_note": "Require each module's report permission and source-object Site/privacy scope; a hub never grants family-inherited export authority.",
    },
    {
        "candidate_id": "CAP-QUAL-INTERNAL-QUALITY-CHECKLIST",
        "module": "Quality/Internal",
        "feature_class": "M",
        "user_job": "Review the authenticated internal quality checklist",
        "canonical_owner": "QualityChecklistController",
        "production_anchors": ["routes/web.php:130", "app/Http/Controllers/QualityChecklistController.php:7-15"],
        "representative_test_anchors": ["tests/Browser/Misc/MiscPagesTest.php:76-80"],
        "site_role_privacy_note": "Its intended production role and exact reports, audit, or settings permission remain an explicit decision gap.",
    },
]
assert len(ADDITIONS) == 14
assert Counter(row["feature_class"] for row in ADDITIONS) == {"H": 12, "D": 1, "M": 1}
for ordinal, row in enumerate(ADDITIONS, start=1):
    row.update(
        {
            "ordinal": ordinal,
            "assignment_id": "RUN-012",
            "adjudication_status": "GROUPED_DISCOVERY_CANDIDATE_NOT_FINAL_DENOMINATOR",
            "evidence_limit": "Owner-backed static source and test-presence evidence only; no runtime, test execution, browser, benchmark, ease, release, final feature identity, or completion credit.",
        }
    )
    for anchor in row["production_anchors"] + row["representative_test_anchors"]:
        path = anchor.split(":", 1)[0]
        assert (REPO_DIR / path).exists(), f"missing anchor path: {path}"


ROUTE_PAYLOAD = {
    "schema_version": 1,
    "status": "CURRENT_ROUTE_NAVIGATION_GAP_WAVE_01_RECONCILED_NO_RUNTIME_CREDIT",
    "generated_at": GENERATED_AT,
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_input_commit": AUDIT_INPUT_COMMIT,
        "routes_tree": "9b7f78510d970db64ea3a6540e8a36b8700bf272",
        "resources_tree": "456297db4d145ff77381cb69b47af3d0ffcd88ed",
        "non_audit_product_diff": 0,
    },
    "methods": ["Tracked-file enumeration", "Static route-call and fluent-name parsing", "Literal Inertia render extraction", "Named navigation/tab registry inspection", "Candidate anchor reconciliation"],
    "route_denominator": {
        "route_php_files": 38,
        "static_route_declaration_callsites": 3217,
        "fluent_name_callsites": 3245,
        "broadcast_channel_registrations": 2,
        "schedule_registrations": 117,
        "artisan_closure_registrations": 1,
        "accounting": {"represented": 21, "alias_compatibility": 5, "composite": 6, "missing_or_partial": 6},
        "rows": [
            {"route_file": path, "route_callsites": calls, "name_callsites": names, "classification": status, "accounted_family": family}
            for path, calls, names, status, family in ROUTE_ROWS
        ],
        "limit": "Static declarations do not establish framework/provider expansion, unique runtime routes, middleware behavior, or reachability.",
    },
    "navigation_denominator": {
        "named_navigation_tab_source_files": 162,
        "persistent_or_canonical_registry_files_manually_inspected": 33,
        "groups_or_sets": 121,
        "declared_items_or_tabs": 492,
        "remaining_page_local_or_renderer_files": 129,
        "contradictions": [
            "Nine system subpanel items are defined but no system icon is declared for the desktop/mobile icon maps; this is a syntactically orphaned locator, not runtime proof.",
            "The /compliance destination appears in Health & Safety and Governance navigation and must not create two feature IDs.",
            "Shared /hr/assets, /reports, and Security Devices aliases are repeated destinations, not independent completion credit.",
            "The application header still declares starter-kit Repository and Documentation links; runtime reachability is unproved.",
        ],
    },
    "literal_inertia_owners": {
        "literal_render_callsites": 745,
        "existing_render_callsites": 734,
        "unique_render_names": 722,
        "owner_files": 377,
        "render_roots": 53,
        "existing_page_roots": 711,
        "missing_render_targets": 11,
        "commented_callsite_excluded": "app/Http/Controllers/ClientController.php:2408",
    },
    "candidate_reconciliation": {
        "prior_grouped_candidates": 172,
        "provisional_additions": 14,
        "provisional_discovery_floor": 186,
        "addition_class_counts": dict(Counter(row["feature_class"] for row in ADDITIONS)),
        "identical_production_anchor_groups": 8,
        "rows_in_identical_anchor_groups": 62,
        "semantic_collision_examples": [
            "CAP-OPS-CARE-PLAN-LIFECYCLE versus CAP-OPS-CARE-PLAN-REVIEW-SIGNOFF",
            "CAP-INC-SAFEGUARDING-* versus CAP-SAFE-*",
            "CAP-INT-ADMIN-CONNECTIONS versus Settings webhooks/API and Security Devices provider integrations",
            "Shared /compliance, /reports, /hr/assets, and legacy redirect destinations",
        ],
        "additions": ADDITIONS,
        "limit": "The additions establish only a discovery floor. Existing collisions may split or collapse, so 186 is not a frozen canonical feature denominator.",
    },
    "evidence_count": 4461,
    "evidence_count_basis": {"route_file_identities": 38, "static_route_callsites": 3217, "schedule_registrations": 117, "broadcast_channel_registrations": 2, "literal_inertia_render_callsites": 745, "named_navigation_tab_source_files": 162, "prior_candidate_records": 172, "product_subtree_hash_comparisons": 8},
    "execution_credit": {"laravel_boot": 0, "route_runtime": 0, "tests": 0, "build": 0, "database": 0, "network": 0, "browser": 0, "benchmark": 0, "completion": 0},
    "completion_test_met": True,
}


VISUAL_PAYLOAD = {
    "schema_version": 1,
    "status": "CURRENT_VISUAL_STATIC_CENSUS_WAVE_01_RECONCILED_NO_RENDERED_CREDIT",
    "generated_at": GENERATED_AT,
    "pins": {"application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE, "audit_input_commit": AUDIT_INPUT_COMMIT, "resources_js_tree": "1671a7551c004571c48bb00c34522928e6f1f173", "non_audit_product_diff": 0},
    "parser": {"typescript_version": "5.9.3", "parse_diagnostics": 0, "tracked_js_ts_paths": 1965, "excluded_test_story_paths": 204, "production_scope": 1761, "tsx": 1636, "ts": 125, "production_path_list_sha256": "93b62ffe757fdbaa57022a4f3150dcf304809c8316e3230614e3e0cedd99c3fa", "path_blob_manifest_sha256": "2c1da9967c585adad456f76bba7d7957ea341068564ae119d50f2123ac2b0abb", "excluded_path_list_sha256": "4c43b515a16ed11af74497f949223734bf702b2bdbd29b1ef0aa4ff33672ca21"},
    "heroes": {
        "definitions": 57,
        "definition_partition": {"primitive": 2, "wrapper": 24, "custom": 31},
        "reuse_locator": {"shared": 11, "single_consumer": 34, "no_external_consumer": 12},
        "instances": 659,
        "instance_partition": {"primitive": 553, "defined_surface": 106},
        "primitive_instances": {"PageHero": 504, "HeroShell": 49},
        "unresolved_named_instance_symbols": 0,
        "inline_candidate_locators_outside_definitions": 79,
        "hashes": {"definitions": "6fb3ee77e7c19d8179962cf40649268031903cf1f4661247b007aab417282cc2", "instances": "6ff0785548a0c49a0520ba767882ce84307643279e1f9375e02790813a691cce", "inline_locators": "0c4761e6a262f15e52443ebb59405208022b489fa12d1d8af0315825619c0aa0"},
    },
    "overlays": {
        "definitions": 473,
        "definition_partition": {"primitive": 4, "wrapper": 272, "custom_or_host": 197},
        "reuse_locator": {"shared": 97, "single_consumer": 273, "no_external_consumer": 103},
        "instances": 1211,
        "instance_partition": {"primitive_root": 494, "defined_wrapper": 715, "injectable_alias": 2},
        "primitive_roots": {"Dialog": 379, "AlertDialog": 52, "Popover": 45, "Sheet": 18},
        "material_state_partition": {"controlled_expression": 795, "literal_open": 162, "conditional_mount": 155, "no_explicit_open_state": 99},
        "inline_aria_dialogs_outside_named_definitions": 5,
        "hashes": {"definitions": "2380b7ae9a1016d46d311693929a4b69f79d9cb1d3db7d8eb9508011830daf00", "instances": "84462377b62d34d49c25bc78c361579a3f821637a8aaa37d2b4219483d40d1b6", "state_partition": "bae0692de118725b68d4f7bb537fa87d92cf0ac7f666d0080604935eca6f1310", "inline_aria_dialogs": "067e8e53b7a2189f25b3d7c140bf88e83f71b44e92a266d7c942c5d7172b65d3", "no_explicit_state": "c531bb133099dc2110f98b8a4cba27ff72cbff063797a7260a033c0303fb5d43"},
    },
    "triggers": {
        "declarative_primitive_tags": 115,
        "declarative_partition": {"DialogTrigger": 57, "PopoverTrigger": 45, "AlertDialogTrigger": 10, "SheetTrigger": 3},
        "exact_use_state_setter_pairs": 664,
        "setter_transitions": 2162,
        "setter_transition_partition": {"close": 1034, "value_open": 640, "explicit_open": 458, "bidirectional_or_toggle": 30},
        "direct_inline_opening_handler_sites": 689,
        "local_named_handler_reference_sites": 138,
        "excluded_change_or_close_bindings": 44,
        "positive_transition_calls_supporting_only": 1098,
        "hashes": {"declarative": "bc0f21fc8b9e858c0786d9b1f91803ee805892b533cd3c247261348d2c5fac63", "state_transitions": "1d68655c73099455736dbf5ec3ea75dcf75ae68a4a8bfdb96c1a66b9afb8ce57", "inline_handlers": "34fe8392d3fdcba8d1bcacbdd0f00c57b0c87fc2b37f901a825d4f03cf086cfd", "named_handlers": "a5fe095ec8727fa2c8d395978d7dd8f4cbd8b3568a7e7731c9bfaac629c2c07f", "excluded_bindings": "bf30e7c9839fd816664551a9a780c961e27da7738e05fe3c975721f4428a81fd"},
    },
    "static_linkage": {
        "hero_sites": {"unique_rendered_page_root": 619, "multiple_rendered_roots": 26, "unresolved_rendered_root": 14, "direct_literal_render_owner": 645, "route_owner_inferred": 644, "direct_candidate_anchor_match": 134, "route_inferred_candidate_match": 420, "role_capability_token_hint": 135, "site_token_hint": 90, "privacy_token_hint": 43},
        "overlay_sites": {"unique_rendered_page_root": 987, "multiple_rendered_roots": 184, "unresolved_rendered_root": 40, "direct_literal_render_owner": 1171, "route_owner_inferred": 1171, "direct_candidate_anchor_match": 347, "route_inferred_candidate_match": 854, "role_capability_token_hint": 458, "site_token_hint": 449, "privacy_token_hint": 84},
        "limit": "Route ownership is inferred through static controller references; candidate and access-hint linkage is source-only and grants no final identity or authorization credit.",
    },
    "evidence_count": 4276,
    "evidence_count_basis": {"scanned_files": 1761, "hero_definitions": 57, "hero_instances": 659, "overlay_definitions": 473, "overlay_instances": 1211, "declarative_trigger_tags": 115},
    "execution_credit": {"browser": 0, "build": 0, "runtime": 0, "route_execution": 0, "tests": 0, "database": 0, "network": 0, "rendered_visual_coverage": 0, "access_control": 0},
    "completion_test_met": True,
    "row_materialization_status": "OPEN_RUN_016_REPRODUCTION_CONTRACT_PENDING",
}


BACKEND_SCOPES = [
    ("app_php", 3110, 3111, "8fc18a3299bc3eb64d46a6c1a5cecde1ece314f55e6dd84cd1895cf776e479ea"),
    ("app_domain_php", 1381, 1384, "b28d1596ea1efcee0491619b44a3641e78247ce84d814b27c941b28948beb79f"),
    ("controller_directories", 561, 560, "4932ff0338cd82906ed2372ff473e9df4cc1c37146d7e99a3a4e4199ec8e2b3f"),
    ("service_entry_union", 735, 735, "4259534e12194522c2f418ab2821f4e0d877f21d5af5d0aa4cd3088eb74ca2e8"),
    ("model_directories", 782, 782, "c2991205d806d6afee62f8488a3526bc9a9ba8da676ba972e31cf86840bede30"),
    ("policy_directories", 75, 75, "5d54347fb0f68c16a3a0a6151cd4e108c7995f122e6c6aad9f95dd7c35616a8a"),
    ("job_directories", 126, 126, "8f4e3fdd0b88e25baa89e83bdd5c3ed8e2dd690986cb9f79e8e3ef2d8323e1ae"),
    ("event_directories", 14, 14, "af8bac96fb3fe68e45552972280709021843e8bc4671f7366f1987444d97a70a"),
    ("listeners", 12, 12, "949b9d1095f90081655fde745b6a3769fda2dfddc746eb68f87e78100592ace0"),
    ("observers", 29, 29, "5d44e0be6ebddf1a72fe4e66904237887e207b5f50a88776abfbdfb5931ca46d"),
    ("notification_path_lens", 176, 176, "35a754f4c95bbc81b2c7dac893fbf1283e11eb4baf605fa8c40b749d0c992045"),
    ("migrations", 978, 978, "91fa419a68468ac67213be95f2c898c2c1fe2dbb5352c3608f12049d606085b8"),
    ("php_test_files", 1381, 9895, "f1bb1a295a3b172a656f0147e3eb4ad5509446acaf6158ba7badfad93932dd99"),
    ("factories", 145, 145, "c5b65bb13c5c125eea0dd4675790fb83ef56eabe20aecf5489e31451db494898"),
    ("seeders_php", 83, 83, "0ed48e90edb1fa37da70089d2842468eeee0ec30996a552092a2c0e159ffeb9e"),
]


BACKEND_PAYLOAD = {
    "schema_version": 1,
    "status": "CURRENT_BACKEND_DATA_TEST_CENSUS_WAVE_01_RECONCILED_NO_EXECUTION_CREDIT",
    "generated_at": GENERATED_AT,
    "pins": {"application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE, "audit_input_commit": AUDIT_INPUT_COMMIT, "non_audit_product_diff": 0},
    "methods": ["Committed-path enumeration", "Lexical PHP declaration/import scan", "Candidate anchor reconciliation", "Static schema-dump and migration-ledger text comparison"],
    "denominators": [{"scope": scope, "paths": paths, "declarations_or_static_units": units, "sorted_path_sha256": sha} for scope, paths, units, sha in BACKEND_SCOPES],
    "module_arithmetic": {
        "domain": {"Clinical": 42, "Finance": 263, "Governance": 138, "Hr": 324, "It": 43, "Monitoring": 266, "Privacy": 7, "Roadmap": 57, "Rostering": 10, "SecurityDevices": 223, "Shifts": 8, "total": 1381, "hash": "2974ddeadba9b80cf8f0525f67fbf0de05280513428018e23778bd802f15e201"},
        "controllers": {"domain": 125, "http": 436, "total": 561, "hash": "f055fb7cab73e4d58e0ace143c93da6c2f71e1e32096aa8453467dc4bbf2dec3"},
        "service_entry_union": {"domain": 371, "top_level": 364, "total": 735, "hash": "86675b783251e6f7ac8d17027cc6fced4bdee418aee31ea90a571e98073c19d5"},
        "models": {"domain": 348, "top_level": 434, "total": 782, "hash": "3fab9fa2b12e07943d4a0f50c2e0616ff0e67a8dfa92c0e62b4d8c426aafdd65"},
        "policies": {"domain": 43, "top_level": 32, "total": 75, "hash": "a6c9faf6560a0416a789a21aca522cfbd93113708bd619ff0f68c54193954a24"},
        "jobs": {"domain": 74, "top_level": 52, "total": 126, "hash": "137d2799a2ee1132ac09680d1bc89294ec1ddc4286561667bb6c86f30d18c41e"},
        "events": {"domain": 8, "top_level": 6, "total": 14, "hash": "232c1f78bbeedb87b1210a57e9fc45bdede0b60e009b8c2afe1238ed9b2b97b9"},
        "listeners": {"total": 12, "hash": "17bfb3e4e7fd5a5319792090a32ce22100bad1d3bbd8d614623278e25d5ed990"},
        "observers": {"total": 29, "hash": "1cf47c4ab0207b61ff1e003c0bb47fcb46549c87d06ae22c9f53b4c796003c16"},
    },
    "migration_filename_primary_mapping": {"Clinical/eMAR/Clients/Care": 153, "Finance": 119, "Fleet/Assets/Sites": 106, "Governance/Roadmap": 45, "H&S/Safeguarding/Incidents": 90, "HR/Workforce/Operations": 209, "IT/Integrations": 46, "Platform/System": 61, "Privacy/Audit": 16, "Security Devices/Monitoring": 63, "Unclassified": 70, "total": 978, "mapping_hash": "0c3cdc231a9ebf2c2cfbf25fe71b92518f9a366465935367a231a09f5ac626e2", "multi_rule_paths": 209, "multi_rule_hash": "657c6f6e48c9fc0a0a1f7e2b930eac9f32ed55fbb030fc1b3049bc038b7ed00a", "unclassified_hash": "f49f79c71361efc74133814ab206c1a2aca9517e249c14a44e81912a8379a34c", "limit": "Filename mapping is heuristic and migration history is not current schema truth."},
    "tests_static": {"php_files": 1381, "lexical_cases": 9895, "non_php_artifacts": 128, "families": {"Architecture": [52, 134], "Browser": [118, 600], "Feature": [1042, 8287], "Integration": [1, 2], "Performance": [1, 5], "Support": [18, 0], "Unit": [145, 867], "fixtures": [1, 0], "root_support": [3, 0]}, "primary_mapping_hash": "a8167347abdc4aa8bb5475a0ede89960fe51de8e681213a73dd2834fea480177", "execution_credit": 0},
    "candidate_linkage": {"candidate_rows": 172, "candidate_id_hash": "ccba3c435b0155313bec4b0038f298e04f056d294a2087a27949ea7c13493ea5", "production_anchor_occurrences": 358, "distinct_production_anchor_strings": 141, "normalized_production_paths": 130, "test_anchor_occurrences": 280, "distinct_test_anchor_strings": 132, "normalized_test_paths": 121, "exact_controller_paths": 62, "controller_candidate_ids": 102, "exact_service_entry_paths": 54, "service_candidate_ids": 100, "exact_domain_paths": 45, "domain_candidate_ids": 65, "exact_model_policy_job_event_listener_observer_notification_migration_paths": 0, "named_owner_candidates": 120, "candidates_without_class_like_owner_names": 52, "candidates_omitting_existing_named_owner": 50, "omitted_candidate_owner_pairs": 62, "omitted_pair_hash": "e63098c38f4ac8e226639af9e10b4092c042a9f92a676a27750277d828ba4fe8", "candidates_omitting_every_named_owner": 19, "all_omitted_names_resolve_to_source": True, "limit": "The linkage measures exact anchors in the prior 172-row discovery register; it is not coverage of the 14 RUN-012 additions and grants no canonical ownership credit."},
    "ownership_operability_locators": {
        "explicit_policy_map_pairs": 65,
        "models_without_explicit_map": 717,
        "models_without_explicit_map_or_local_scope_method": 444,
        "policy_files_outside_explicit_map": 10,
        "jobs_without_explicit_retry_failure_signal": 61,
        "jobs_without_basename_test_reference": 47,
        "jobs_in_both_sets": 31,
        "jobs_without_external_basename_reference": 12,
        "outbox_app_files": 45,
        "named_outbox_owners": 12,
        "outbox_migration_files": 7,
        "outbox_test_files": 32,
        "cross_domain_import_occurrences": 328,
        "cross_domain_import_files": 159,
        "directed_domain_edges": 27,
        "reciprocal_edge_pairs": 11,
        "limit": "These are review locators. Policy auto-discovery, service/query authorization, runtime reachability, and dependency cycles were not executed or proven.",
    },
    "conservative_high_risk_records": [
        {"record_id": "SRC-CALENDAR-SYNC-TRUTHFULNESS", "summary": "The trigger updates last_synced_at and returns success while dispatch is commented out; SyncCalendarJob catches provider exceptions without rethrowing.", "anchors": ["app/Http/Controllers/Operations/CalendarSyncController.php:73-89", "app/Jobs/SyncCalendarJob.php:21-56", "tests/Feature/Operations/CalendarSyncTriggerTest.php:9-22"], "status": "PROVISIONAL_STATIC_CLAIM_NOT_FINAL_FINDING"},
        {"record_id": "SRC-AUTH-EVENT-SUBSCRIBER-REACHABILITY", "summary": "AuthEventSubscriber owns authentication evidence but has no external basename reference; discovery is disabled and EventServiceProvider does not list it.", "anchors": ["app/Listeners/AuthEventSubscriber.php:13-66", "app/Providers/EventServiceProvider.php:41-90", "app/Models/UserLoginLog.php:9"], "status": "PROVISIONAL_STATIC_CLAIM_NOT_FINAL_FINDING"},
        {"record_id": "SRC-FINANCE-POSTING-PARTIAL-FAILURE", "summary": "Three finance posting jobs catch per-aggregate failures and continue; none has an exact class-name test reference.", "anchors": ["app/Domain/Finance/Jobs/PostLeaveProvisionJob.php", "app/Domain/Finance/Jobs/PostSiteRentJob.php", "app/Domain/Finance/Jobs/PostSiteUtilitiesJob.php"], "status": "PROVISIONAL_STATIC_CLAIM_NOT_FINAL_FINDING"},
        {"record_id": "SRC-SCHEMA-HISTORY-DRIFT", "summary": "The committed schema dump has 784 CREATE TABLE statements and 905 migration-ledger rows; 73 of 978 migration files are absent from that ledger. Six table names have duplicate guarded forward-create owners.", "anchors": ["database/schema/mysql-schema.sql"], "status": "PROVISIONAL_STATIC_SCHEMA_HISTORY_LOCATOR_NOT_DATABASE_TRUTH"},
        {"record_id": "SRC-LEGACY-ARTIFACT-LOCATORS", "summary": "Two definite artifact locators are an 11-byte unreferenced AllowedSiteTypes.php containing only -NoNewline and a zero-byte app/Services/Rag/test blob.", "anchors": ["app/Http/Controllers/Concerns/AllowedSiteTypes.php", "app/Services/Rag/test"], "status": "PROVISIONAL_STATIC_ARTIFACT_LOCATOR"},
    ],
    "evidence_count": 5648,
    "evidence_count_basis": {"unique_committed_php_paths_across_app_migrations_tests": 5469, "prior_candidate_rows": 172, "verified_source_subtree_identities": 7},
    "execution_credit": {"runtime": 0, "tests": 0, "database": 0, "build": 0, "network": 0, "browser": 0},
    "completion_test_met": True,
}


WAVE_03_PAYLOAD = {
    "schema_version": 1,
    "status": "CURRENT_FEATURE_DISCOVERY_WAVE_03_PARTIAL_NOT_CANONICAL_DENOMINATOR",
    "generated_at": GENERATED_AT,
    "source": {"application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE, "audit_input_commit": AUDIT_INPUT_COMMIT, "non_audit_product_diff": 0},
    "candidate_count": len(ADDITIONS),
    "class_counts": dict(Counter(row["feature_class"] for row in ADDITIONS)),
    "module_counts": dict(Counter(row["module"] for row in ADDITIONS)),
    "cumulative_discovery_counts": {"prior_rows": 172, "new_rows": 14, "provisional_floor": 186, "H": 157, "D": 27, "M": 2},
    "candidates": ADDITIONS,
    "provisional_findings": [],
    "limits": ["These additions close named route/navigation family gaps only.", "The 186-row sum is a provisional discovery floor; it is not a frozen canonical feature denominator.", "No runtime, browser, test, benchmark, ease, journey, release, or completion credit is awarded."],
}


ASSIGNMENTS = [
    {"assignment_id": "RUN-012", "agent_task_path": "/root/current_module_route_gap", "role": "route, navigation, and module-gap reconciler", "pass_lens": "Pass 1 repository census and reachability", "scope": "38 route files, 162 named navigation/tab files, literal render owners, prior 172 candidates, and 14 owner-backed additions", "evidence_count": 4461, "observed_heads": ["c7538c937185c78a2f14111a029ca1a6cad3b12f", "066fc516fe489048c26e4ddc7a4d4ae1267b78ed"], "completion_test_met": True, "wrote_files": False, "root_reconciliation": "All 38 route files and 162 navigation/tab files are accounted statically; 14 additions raise only the provisional discovery floor to 186."},
    {"assignment_id": "RUN-013", "agent_task_path": "/root/current_visual_static_census", "role": "production hero, overlay, and trigger census", "pass_lens": "Pass 1 visual denominator and Pass 4 static support", "scope": "1,761 production JS/TS files; hero, overlay, material-state, and trigger universes", "evidence_count": 4276, "observed_heads": ["c7538c937185c78a2f14111a029ca1a6cad3b12f", "066fc516fe489048c26e4ddc7a4d4ae1267b78ed"], "completion_test_met": True, "wrote_files": False, "root_reconciliation": "Primary static universes reconcile; full row materialization and rendered/browser evidence remain open."},
    {"assignment_id": "RUN-014", "agent_task_path": "/root/current_backend_data_test_census", "role": "backend, data-history, async, policy, and test census", "pass_lens": "Passes 1, 5, and 7 static support", "scope": "Application PHP, controllers, services, models, policies, async owners, migrations, schema dump, factories, seeders, and PHP tests", "evidence_count": 5648, "observed_heads": ["c7538c937185c78a2f14111a029ca1a6cad3b12f", "779fdea9d24b444738396698c2b9001c686ba144"], "completion_test_met": True, "wrote_files": False, "root_reconciliation": "Directory/declaration denominators and locators are accepted as static evidence only; schema truth, execution coverage, runtime reachability, and final findings remain open."},
]
for assignment in ASSIGNMENTS:
    assignment.update({"application_commit": APPLICATION_COMMIT, "architecture_rule": "Single tenant, multiple Sites; roles/action authority, approved-Site scope, canonical ownership, concealed direct IDs, and privacy—not tenant isolation.", "no_write_rule": "Return structured evidence in the agent message; do not edit repository files.", "normalized_payload_sha256": digest(assignment)})


AGENT_PAYLOAD = {
    "schema_version": 1,
    "status": "FORMAL_STATIC_COVERAGE_WAVE_01_RECONCILED_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "application_commit": APPLICATION_COMMIT,
    "writer_boundary": "Only the root orchestrator wrote audit artifacts; RUN-012 through RUN-014 returned evidence in messages and reported wrote_files=false.",
    "wave_formal_assignments_eligible": 3,
    "cumulative_formal_assignments_eligible": 14,
    "literal_prompt_minimum": 8,
    "literal_prompt_minimum_met": True,
    "planned_formal_assignments_target": 11,
    "planned_target_met": True,
    "all_returned": True,
    "all_completion_tests_met": True,
    "all_reported_no_writes": True,
    "outstanding_required_roles_or_waves": ["RUN-015 official GitHub metadata normalization", "RUN-016 full visual-row materialization contract", "canonical identity/collision adjudication", "fresh Pass 8 cross-reviewers", "final no-live-agent reconciliation"],
    "assignment_returns": ASSIGNMENTS,
    "finalization_gate": False,
}


LEDGER_FIELDS = ["module", "submodule", "scope_anchor", "route_callsites", "name_callsites", "source_classification", "P1", "P2", "P3", "P4", "P5", "P6", "P7", "P8", "agent_assignments", "evidence_count", "gaps", "reconciliation", "completion_credit"]
P1_BY_CLASS = {"R": "PARTIAL_STATIC_ROUTE_FILE_REPRESENTED_NO_RUNTIME", "A": "PARTIAL_STATIC_ALIAS_CLASSIFIED_NO_RUNTIME", "C": "PARTIAL_STATIC_COMPOSITE_CLASSIFIED_NO_RUNTIME", "M/P": "PARTIAL_STATIC_ROUTE_FAMILY_GAP_IDENTIFIED_NO_RUNTIME"}
LEDGER_ROWS = []
for path, calls, names, status, family in ROUTE_ROWS:
    LEDGER_ROWS.append({
        "module": family,
        "submodule": path.removeprefix("routes/").removesuffix(".php"),
        "scope_anchor": path,
        "route_callsites": calls,
        "name_callsites": names,
        "source_classification": status,
        "P1": P1_BY_CLASS[status],
        "P2": "NOT_STARTED_CURRENT_AUDIT",
        "P3": "NOT_STARTED_OR_NOT_MAPPED_TO_FROZEN_FEATURES",
        "P4": "GLOBAL_STATIC_VISUAL_CENSUS_AVAILABLE_ROUTE_SPECIFIC_RENDERED_REVIEW_NOT_STARTED",
        "P5": "GLOBAL_BACKEND_LOCATORS_AVAILABLE_ROUTE_SPECIFIC_OWNERSHIP_NOT_COMPLETE",
        "P6": "NOT_STARTED_CURRENT_AUDIT",
        "P7": "GLOBAL_STATIC_TEST_OPERABILITY_LOCATORS_AVAILABLE_NO_EXECUTION",
        "P8": "NOT_STARTED_CURRENT_AUDIT",
        "agent_assignments": "RUN-012; RUN-013; RUN-014",
        "evidence_count": 1,
        "gaps": "Framework-expanded reachability, canonical FEATURE-ID ownership, task/ease, benchmark, rendered role/Site/browser states, runtime tests, and independent Pass 8 remain open.",
        "reconciliation": "One provisional route-file ownership row; the 38-row ledger is not the canonical module/submodule denominator.",
        "completion_credit": "false",
    })


def main() -> None:
    write_json("evidence/source/current-route-navigation-gap-wave-01.json", ROUTE_PAYLOAD)
    write_json("evidence/source/current-visual-static-census-wave-01.json", VISUAL_PAYLOAD)
    write_json("evidence/source/current-backend-data-test-census-wave-01.json", BACKEND_PAYLOAD)
    write_json("evidence/source/current-feature-discovery-wave-03.json", WAVE_03_PAYLOAD)
    write_json("evidence/source/current-static-coverage-agent-register.json", AGENT_PAYLOAD)
    write_csv("02-eight-pass-coverage-ledger.csv", LEDGER_FIELDS, LEDGER_ROWS)


if __name__ == "__main__":
    main()
