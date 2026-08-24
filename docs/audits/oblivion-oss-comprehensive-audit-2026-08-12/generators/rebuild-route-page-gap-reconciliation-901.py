from __future__ import annotations

import hashlib
import json
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
ROOT = AUDIT / "evidence" / "source"
MANIFEST = ROOT / "working-capability-manifest-901.json"
OUTPUT = ROOT / "route-page-gap-reconciliation-901.json"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"


def ids(prefix: str, compact: str) -> list[str]:
    return [f"{prefix}-{part.strip()}" for part in compact.split(",") if part.strip()]


def target_entry(compact: str, targets: list[str], **extra: object) -> dict[str, object]:
    result: dict[str, object] = {"route_ids": ids("ROUTE", compact), "target_ids": targets}
    result.update(extra)
    return result


manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
manifest_ids = {row["working_key"] for row in manifest["targets"]}
manifest_id_sha = hashlib.sha256("\n".join(sorted(manifest_ids)).encode("utf-8")).hexdigest()

accepted_new = [
    "CAP-AUTH-EMAIL-VERIFICATION-LIFECYCLE",
    "CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT",
    "CAP-HR-VETTING-CHECK-REGISTER-EXPORT",
    "CAP-OPS-CLIENT-FUND-TRANSACTIONS",
    "HR-COMPLIANCE-EXPORT",
    "HR-REFERENCE",
    "OPS-CLIENT-FUND",
]

route_groups: dict[str, list[dict[str, object]]] = {
    "accepted_new_exact": [
        target_entry("0095,0096", ["HR-REFERENCE"]),
        target_entry("0324,0325,0326", ["CAP-AUTH-EMAIL-VERIFICATION-LIFECYCLE"]),
        target_entry(
            "1364",
            [
                "HR-COMPLIANCE-EXPORT",
                "CAP-HR-VETTING-CHECK-REGISTER-EXPORT",
                "CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT",
            ],
            selectors={
                "staff": "HR-COMPLIANCE-EXPORT",
                "vetting": "CAP-HR-VETTING-CHECK-REGISTER-EXPORT",
                "drivers": "CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT",
                "renewals": "dead_branch_excluded",
            },
        ),
        target_entry("1927,1928,1929,1930,1932", ["OPS-CLIENT-FUND"]),
        target_entry("1931", ["CAP-OPS-CLIENT-FUND-TRANSACTIONS"]),
    ],
    "existing_exact": [
        target_entry("0044,0045,0046", ["FLEET-ASSET"]),
        target_entry("0052,0053", ["FLEET-GEOFENCE"]),
        target_entry("0063", ["FLEET-ALERT"]),
        target_entry("0070,0071", ["CAP-OPS-FAMILY-NOTE-SHIFT-HANDOFF"]),
        target_entry("0097", ["CAP-SITE-MEAL-PLANNING"]),
        target_entry("0115", ["CAP-SITE-SITE-CHECKLIST-RUN-EXECUTION"]),
        target_entry("0167,2011", ["CAP-MED-MEDICATION-ORDER-LIFECYCLE"]),
        target_entry("0202,0204,0206", ["CAP-HR-COMPETENCY-DEFINITIONS"]),
        target_entry("0361,1872", ["CAP-MED-EMAR-WORKSPACE"]),
        target_entry("0445", ["CAP-MED-BREAK-GLASS-OVERSIGHT"]),
        target_entry("0474", ["CAP-FIN-AUDIT-EXPORT-PACKAGE"]),
        target_entry("0479,0480", ["FIN-BANK-ACCOUNT"]),
        target_entry("0497", ["FIN-BANK-ACCOUNT", "CAP-FIN-BANK-FEED-CONNECTION-SYNC", "FIN-PETTY-CASH"]),
        target_entry("0514", ["FIN-CASH-FLOW-FORECAST"]),
        target_entry("0535", ["FIN-CREDIT-NOTE"]),
        target_entry("0541", ["FIN-FINANCE-DASHBOARD"]),
        target_entry("0551", ["CAP-FIN-DONOR-FUND-LIFECYCLE"]),
        target_entry("0552", ["CAP-FIN-EFTPOS-TERMINALS"]),
        target_entry("0570,0571", ["CAP-ASSET-FIXED-ASSET-REGISTER"]),
        target_entry("0619", ["FIN-CHART-OF-ACCOUNTS", "FIN-COST-CENTRE", "CAP-ASSET-FIXED-ASSET-REGISTER", "FIN-FX-REVALUATION"]),
        target_entry("0624", ["FIN-BILL"]),
        target_entry("0643", ["FIN-PETTY-CASH"]),
        target_entry("0648,0652", ["FIN-PRICE-BOOK"]),
        target_entry("0668,0670", ["CAP-FIN-QUOTE-LIFECYCLE"]),
        target_entry("0679,0680", ["FIN-RECURRING-CHARGE"]),
        target_entry("0681", ["CAP-FIN-FINANCIAL-REPORT-PROFIT-LOSS"]),
        target_entry("0691", ["CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "FIN-FUNDING-STREAM"]),
        target_entry("0694", ["FIN-GST-RETURN", "FIN-IRD-FILING", "CAP-FIN-AUDIT-EXPORT-PACKAGE", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION"]),
        target_entry("0724,0725,0728,0730", ["CAP-FLEET-DEVICE-REGISTRY-PAIRING"]),
        target_entry("0726,0727,0729", ["CAP-FLEET-DEVICE-TRACKING-CONSENT"]),
        target_entry("0772,0773", ["CAP-FLEET-CHECKLIST-TEMPLATE-DEFINITION"]),
        target_entry("0774,0775", ["CAP-FLEET-CHECKLIST-RUN-EXECUTION"]),
        target_entry("0842", ["FLEET-DASHBOARD"]),
        target_entry("0844", ["CAP-FLEET-VEHICLE-FUEL"]),
        target_entry("0845", ["CAP-FLEET-REPORT-OVERVIEW"]),
        target_entry("0847,0850", ["CAP-FLEET-TRIP-PLAYBACK"]),
        target_entry("0851", ["CAP-FLEET-VEHICLE-REGISTER"]),
        target_entry("0912", ["CAP-GOV-EXECUTIVE-BOARD-COCKPIT"]),
        target_entry("1254", ["CAP-HR-MY-HR-HOME-DIRECTORY-BENEFITS"]),
        target_entry("1296", ["CAP-HR-CALENDAR-EVENT-MANAGEMENT", "CAP-HR-CALENDAR-PARTICIPATION"]),
        target_entry("1322", ["CAP-HR-BENEFITS-PLAN-ADMINISTRATION", "CAP-HR-BENEFITS-ENROLLMENT"]),
        target_entry("1392,1394,1600,1601,1608", ["CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE"]),
        target_entry("1393", ["CAP-HR-MY-HR-HOME-DIRECTORY-BENEFITS"]),
        target_entry("1482", ["CAP-HR-IMPORT-EXPORT-EMPLOYEE-EXPORT", "CAP-HR-IMPORT-EXPORT-EMPLOYEE-IMPORT", "CAP-HR-IMPORT-EXPORT-TEMPLATE"]),
        target_entry("1486,1667,1691,1701", ["HR-RECRUITMENT"]),
        target_entry("1702", ["HR-INTERVIEW-KIT"]),
        target_entry("1769,1770,1771,1772", ["CAP-HR-WELLBEING-SURVEYS"]),
        target_entry("1838,1840", ["CAP-INC-INCIDENT-AUTHOR", "CAP-INC-INCIDENT-EVIDENCE-MANAGEMENT", "CAP-INC-INCIDENT-FOLLOWUP", "CAP-INC-INCIDENT-REVIEW-CLOSURE"]),
        target_entry("1860", ["CAP-SEC-UNIFI-DEFAULTS"]),
        target_entry("1892", ["CAP-DAY-MY-DAY-WORKSPACE"]),
        target_entry("1947", ["CAP-OPS-CLIENT-RECORD-LIFECYCLE"]),
        target_entry("2127", ["CAP-OPS-CLIENT-NOTE", "CAP-OPS-CLIENT-RECORD-LIFECYCLE"]),
        target_entry("2128,2129,2130", ["CAP-OPS-CLIENT-NOTE"]),
        target_entry("2143", ["CAP-OPS-ROSTERING-PLANNING"]),
        target_entry("2144,2145", ["CAP-OPS-ROSTERING-PLANNING", "CAP-OPS-SHIFT-PLANNING-PUBLISH"]),
        target_entry("2234", ["CAP-OPS-TIMESHEET-MANAGER-REVIEW"]),
        target_entry("2238", ["CAP-OPS-TIMESHEET-AUTHOR-SUBMIT"]),
        target_entry("2357", ["OPS-SHIFT-REPORT"]),
        target_entry("2498", ["CAP-OPS-ROSTERING-PLANNING"]),
        target_entry("2500,2501", ["CAP-OPS-STAFF-TIME-OFF"]),
        target_entry("2502", ["CAP-INC-SAFEGUARDING-ACTION-PLAN", "CAP-INC-SAFEGUARDING-CONCERN-INTAKE", "CAP-INC-SAFEGUARDING-EVIDENCE-MANAGEMENT", "CAP-INC-SAFEGUARDING-EXTERNAL-REPORT", "CAP-INC-SAFEGUARDING-INVESTIGATION", "CAP-INC-SAFEGUARDING-RISK-ASSESSMENT", "CAP-INC-SAFEGUARDING-STATUS-CLOSURE", "CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP"]),
        target_entry("2504", ["CAP-INC-SAFEGUARDING-ACTION-PLAN", "CAP-INC-SAFEGUARDING-EVIDENCE-DOWNLOAD", "CAP-INC-SAFEGUARDING-EVIDENCE-MANAGEMENT", "CAP-INC-SAFEGUARDING-EXTERNAL-REPORT", "CAP-INC-SAFEGUARDING-INVESTIGATION", "CAP-INC-SAFEGUARDING-RISK-ASSESSMENT", "CAP-INC-SAFEGUARDING-STATUS-CLOSURE", "CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP"]),
        target_entry("2525", ["CAP-OPS-ROSTERING-PLANNING"]),
        target_entry("2653", ["SEC-INTEGRATIONS-HUB"]),
        target_entry("2654", ["CAP-SEC-UNIFI-DEFAULTS"]),
        target_entry("2708,2709,2711,2712,2726", ["CAP-OPS-SHIFT-PLANNING-PUBLISH"]),
        target_entry("2713,2721,2722,2725", ["CAP-OPS-SHIFT-STAFFING-COVER"]),
        target_entry("2714,2718,2720,2723", ["CAP-OPS-SHIFT-EXECUTION-RECOVERY"]),
        target_entry("2724", ["CAP-OPS-MY-DAY-SHIFT-TASK-COMPLETION"]),
        target_entry("2727", ["CAP-OPS-SHIFT-SERIES-LIFECYCLE"]),
        target_entry("2748,2803", ["CAP-SITE-SITE-CHECKLIST-ASSIGNMENT-RUN-CREATION"]),
        target_entry("2836,2837", ["CAP-SITE-MEAL-SERVICE-COMPLETION"]),
        target_entry("2914", ["CAP-SITE-SITE-VENDOR-GLOBAL-DIRECTORY"]),
        target_entry("2917", ["CAP-HR-STAFF-OPERATIONAL-PROFILE"]),
        target_entry("2932", ["CAP-HR-ONBOARDING-CHECKLIST-CASE"]),
        target_entry("2945", ["CAP-REP-SUMMARY-STAFF"]),
        target_entry("2984,2985,2987,2988,2990,2992,2994,2999", ["CAP-OPS-TIMESHEET-AUTHOR-SUBMIT"]),
        target_entry("2989,2991,2993,2995,2996,2997,2998", ["CAP-OPS-TIMESHEET-MANAGER-REVIEW"]),
        target_entry("3001,3002,3003,3004,3005,3006", ["CAP-HR-TRAINING-COURSE-SESSION-CATALOG"]),
        target_entry("3024", ["CAP-HR-STAFF-OPERATIONAL-PROFILE"]),
    ],
    "excluded_dead_or_unreachable": [
        {"route_ids": ids("ROUTE", "0047,0048"), "tombstone_id": "ASSET-ASSET-ASSIGNMENT"},
        {"route_ids": ids("ROUTE", "0054"), "tombstone_id": "ASSET-ASSET-INSPECTION"},
        {"route_ids": ids("ROUTE", "0055"), "tombstone_id": "ASSET-ASSET-MAINTENANCE"},
        {"route_ids": ids("ROUTE", "0056"), "tombstone_id": "ASSET-ASSET-OWNERSHIP"},
        {"route_ids": ids("ROUTE", "0060"), "tombstone_id": "ASSET-ASSET-SCAN-EVENT"},
        {"route_ids": ids("ROUTE", "0404,0405,0406"), "tombstone_id": "MED-REFUSAL-FOLLOW-UP"},
        {"route_ids": ids("ROUTE", "0207"), "reason": "shadowed_by_prior_unconstrained_framework_parameter"},
        {"route_ids": ids("ROUTE", "2719"), "reason": "registered_uri_differs_from_only_caller", "candidate_target_id": "CAP-INC-INCIDENT-AUTHOR"},
    ],
    "medical_reachability_unproved": [
        {"route_ids": ids("ROUTE", "0168,0169,0170,2012,2013,2014"), "candidate_target_id": "CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE"},
        {"route_ids": ids("ROUTE", "0180,2023"), "candidate_target_id": "CAP-MED-CLIENT-MEDICAL-PROFILE"},
    ],
    "unresolved_ambiguity": [{"route_ids": ids("ROUTE", "0098,0124,1873,2499,2710,2986")}],
    "dead_or_noop": [{"route_ids": ids("ROUTE", "0203,0205,0821,2933,2941,2942")}],
    "generated_or_test_only": [{"route_ids": ids("ROUTE", "0001,0002,0003")}],
    "infrastructure_or_out_of_product": [{"route_ids": ids("ROUTE", "1861,2497,2943,2944,3010")}],
}

# Every nonaccepted surface receives a stable excluded disposition ID. These
# IDs are mapping tombstones/evidence-gap identifiers, never accepted H/D/M
# capability rows and never denominator credit.
for group in (
    "excluded_dead_or_unreachable", "medical_reachability_unproved",
    "unresolved_ambiguity", "dead_or_noop", "generated_or_test_only",
    "infrastructure_or_out_of_product",
):
    for relation in route_groups[group]:
        compact = "-".join(route_id.removeprefix("ROUTE-") for route_id in relation["route_ids"])
        relation.setdefault("excluded_disposition_id", f"SURFACE-ROUTE-{compact}-{group.upper().replace('_', '-')}")

page_groups: dict[str, list[dict[str, object]]] = {
    "accepted_new_exact": [
        {"page_ids": ids("PAGE", "0014"), "target_ids": ["CAP-AUTH-EMAIL-VERIFICATION-LIFECYCLE"]},
        {"page_ids": ids("PAGE", "0020"), "target_ids": ["HR-REFERENCE"]},
        {"page_ids": ids("PAGE", "0571,0572"), "target_ids": ["OPS-CLIENT-FUND"]},
        {"page_ids": ids("PAGE", "0573"), "target_ids": ["OPS-CLIENT-FUND", "CAP-OPS-CLIENT-FUND-TRANSACTIONS"]},
    ],
    "existing_exact": [
        {"page_ids": ids("PAGE", "0023"), "target_ids": ["CAP-SITE-MEAL-PLANNING"]},
        {"page_ids": ids("PAGE", "0024"), "target_ids": ["CAP-SITE-PRODUCT-LIFECYCLE"]},
        {"page_ids": ids("PAGE", "0025,0026,0027"), "target_ids": ["CAP-SITE-RECIPE-LIFECYCLE"]},
        {"page_ids": ids("PAGE", "0028"), "target_ids": ["CAP-SITE-DIETARY-TAG-LIFECYCLE"]},
        {"page_ids": ids("PAGE", "0039"), "target_ids": ["CLI-CLIENT-PORTAL-USER"]},
        {"page_ids": ids("PAGE", "0217"), "target_ids": ["CAP-FLEET-DEVICE-REGISTRY-PAIRING", "CAP-FLEET-DEVICE-TRACKING-CONSENT"]},
        {"page_ids": ids("PAGE", "0231"), "target_ids": ["CAP-FLEET-CHECKLIST-TEMPLATE-DEFINITION", "CAP-FLEET-CHECKLIST-RUN-EXECUTION"]},
        {"page_ids": ids("PAGE", "0232"), "target_ids": ["CAP-FLEET-CHECKLIST-RUN-EXECUTION"]},
        {"page_ids": ids("PAGE", "0254"), "target_ids": ["CAP-FLEET-RESIDENT-TRANSPORT-LIFECYCLE"]},
        {"page_ids": ids("PAGE", "0255"), "target_ids": ["CAP-FLEET-RESIDENT-TRANSPORT-MEDICATION-CUSTODY"]},
        {"page_ids": ids("PAGE", "0408"), "target_ids": ["CAP-HR-CALENDAR-EVENT-MANAGEMENT", "CAP-HR-CALENDAR-PARTICIPATION"]},
        {"page_ids": ids("PAGE", "0415"), "target_ids": ["CAP-HR-BENEFITS-PLAN-ADMINISTRATION", "CAP-HR-BENEFITS-ENROLLMENT"]},
        {"page_ids": ids("PAGE", "0447,0449"), "target_ids": ["CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE"]},
        {"page_ids": ids("PAGE", "0461"), "target_ids": ["CAP-HR-IMPORT-EXPORT-EMPLOYEE-EXPORT", "CAP-HR-IMPORT-EXPORT-EMPLOYEE-IMPORT", "CAP-HR-IMPORT-EXPORT-TEMPLATE"]},
        {"page_ids": ids("PAGE", "0532"), "target_ids": ["CAP-IT-PROVISIONING-REQUEST", "CAP-IT-SUPPORT-TICKET"]},
        {"page_ids": ids("PAGE", "0596"), "target_ids": ["CAP-PRIV-SYSTEM-AUDIT-LOG-REVIEW"]},
        {"page_ids": ids("PAGE", "0604"), "target_ids": ["CAP-FIN-CLIENT-FINANCIAL-OVERVIEW"]},
        {"page_ids": ids("PAGE", "0722"), "target_ids": ["CAP-OPS-CONVERSATIONS"]},
        {"page_ids": ids("PAGE", "0810"), "target_ids": ["CAP-ROAD-DECISION-REQUEST", "CAP-ROAD-INITIATIVE-LIFECYCLE", "CAP-ROAD-QUARTERLY-PLAN-PREPARATION", "CAP-ROAD-QUARTERLY-PLAN-APPROVAL-PUBLISH", "CAP-ROAD-SUGGESTION-TRIAGE"]},
        {"page_ids": ids("PAGE", "0865"), "target_ids": ["CAP-SITE-SITE-VENDOR-LIFECYCLE", "CAP-SITE-SITE-VENDOR-GLOBAL-DIRECTORY", "CAP-SITE-SITE-VENDOR-GLOBAL-AUDIT", "CAP-SITE-SITE-CREDENTIAL-LIFECYCLE", "CAP-SITE-SITE-CREDENTIAL-REVEAL-TOTP", "SITE-CREDENTIAL-TYPE"]},
        {"page_ids": ids("PAGE", "0948"), "target_ids": ["CAP-HR-STAFF-OPERATIONAL-PROFILE"]},
        {"page_ids": ids("PAGE", "0955"), "target_ids": ["CAP-SET-USER-ACCOUNT-LIFECYCLE"]},
    ],
    "support_only": [
        {"page_ids": ids("PAGE", "0543,0544,0547,0548,0549,0550,0551,0558,0560,0561"), "target_ids": ["CAP-DAY-MY-DAY-WORKSPACE"]},
        {"page_ids": ids("PAGE", "0679,0681,0683,0684,0685"), "target_ids": ["CAP-OPS-SHIFT-NOTE-AUTHORING", "CAP-OPS-SHIFT-NOTE-REVIEW"]},
        {"page_ids": ids("PAGE", "0846"), "target_ids": ["CAP-SET-ROLE-NOTIFICATION-DEFAULTS", "CAP-SET-NOTIFICATION-ESCALATION-CONFIG", "CAP-SET-PERSONAL-NOTIFICATION-PREFERENCES"]},
    ],
    "resolver_orphan": [{"page_ids": ids("PAGE", "0022,0035,0037,0038,0100,0285,0530,0590,0827,0835,0836,0858,0876,0962")}],
    "dead_or_noop": [{"page_ids": ids("PAGE", "0252")}],
    "generated_or_test_only": [{"page_ids": ids("PAGE", "0559")}],
    "infrastructure_or_out_of_product": [{"page_ids": ids("PAGE", "0531")}],
}

for group in (
    "support_only", "resolver_orphan", "dead_or_noop",
    "generated_or_test_only", "infrastructure_or_out_of_product",
):
    for relation in page_groups[group]:
        if relation.get("target_ids"):
            continue
        compact = "-".join(page_id.removeprefix("PAGE-") for page_id in relation["page_ids"])
        relation.setdefault("excluded_disposition_id", f"SURFACE-PAGE-{compact}-{group.upper().replace('_', '-')}")

route_disposition_labels = {
    "accepted_new_exact": "accepted_new_exact_target",
    "existing_exact": "existing_exact_target",
    "excluded_dead_or_unreachable": "excluded_dead_or_unreachable",
    "medical_reachability_unproved": "medical_reachability_unproved",
    "unresolved_ambiguity": "unresolved_ambiguity",
    "dead_or_noop": "dead_or_noop",
    "generated_or_test_only": "generated_or_test_only",
    "infrastructure_or_out_of_product": "infrastructure_or_out_of_product",
}
page_disposition_labels = {
    "accepted_new_exact": "accepted_new_exact_target",
    "existing_exact": "existing_exact_target",
    "support_only": "support_only",
    "resolver_orphan": "resolver_orphan",
    "dead_or_noop": "dead_or_noop",
    "generated_or_test_only": "generated_or_test_only",
    "infrastructure_or_out_of_product": "infrastructure_or_out_of_product",
}

route_seen: dict[str, str] = {}
route_relation_count = 0
for group, rows in route_groups.items():
    for row in rows:
        target_ids = row.get("target_ids", [])
        for target_id in target_ids:
            if target_id not in manifest_ids:
                raise ValueError(f"Unknown target {target_id}")
        route_relation_count += len(row["route_ids"]) * len(target_ids)
        for route_id in row["route_ids"]:
            if route_id in route_seen:
                raise ValueError(f"Duplicate route {route_id}: {route_seen[route_id]} and {group}")
            route_seen[route_id] = group

page_seen: dict[str, str] = {}
page_relation_count = 0
for group, rows in page_groups.items():
    for row in rows:
        target_ids = row.get("target_ids", [])
        for target_id in target_ids:
            if target_id not in manifest_ids:
                raise ValueError(f"Unknown target {target_id}")
        page_relation_count += len(row["page_ids"]) * len(target_ids)
        for page_id in row["page_ids"]:
            if page_id in page_seen:
                raise ValueError(f"Duplicate page {page_id}: {page_seen[page_id]} and {group}")
            page_seen[page_id] = group

if len(route_seen) != 197:
    raise ValueError(f"Expected 197 routes, got {len(route_seen)}")
if len(page_seen) != 63:
    raise ValueError(f"Expected 63 pages, got {len(page_seen)}")

checksum_lines = [AUDITED_COMMIT, "denominator|901"]
checksum_lines.extend(f"accepted|{target_id}" for target_id in sorted(accepted_new))
checksum_lines.extend(f"{route_id}|{route_disposition_labels[group]}" for route_id, group in sorted(route_seen.items()))
checksum_lines.extend(f"{page_id}|{page_disposition_labels[group]}" for page_id, group in sorted(page_seen.items()))
coverage_sha = hashlib.sha256("\n".join(checksum_lines).encode("utf-8")).hexdigest()

payload = {
    "schema_version": "1.0",
    "artifact": "route-page-gap-reconciliation-901",
    "status": "static_read_only_reconciliation_not_runtime_or_completion_claim",
    "generated_at": manifest.get("generated_at"),
    "audited_commit": AUDITED_COMMIT,
    "audit_boundary": "Static source and inventory reconciliation only. No routes, tests, browser sessions, databases or external systems were executed or changed.",
    "inputs": [
        {"file": MANIFEST.name, "role": "target-identity validation only", "canonical_stable_target_ids_sha256": manifest_id_sha},
        {"source": "inventory.json not-enriched route/page sets", "route_count": 197, "page_count": 63},
        {"source": "independent denominator and reachability adjudication", "accepted_delta": {"total": 7, "H": 4, "D": 3}},
    ],
    "denominator": {"baseline": 894, "accepted_new": 7, "accepted": 901, "accepted_new_class_counts": {"H": 4, "D": 3}, "accepted_new_target_ids": sorted(accepted_new)},
    "normalization_note": "Earlier read-only agent shorthand used descriptive candidate names for four additions. This artifact uses only the stable working keys present in working-capability-manifest-901.json.",
    "excluded_mapping_rule": "Each route/page outside an accepted H/D/M target has a stable SURFACE-* disposition ID. These are excluded classification/tombstone IDs, not user-capability denominator rows and not completion credit.",
    "legend": {**route_disposition_labels, **{f"page_{key}": value for key, value in page_disposition_labels.items()}},
    "routes": route_groups,
    "pages": page_groups,
    "changed_source_anchors": {
        "asset_tombstones": ["routes/assets.php:180-233", "app/Http/Controllers/AssetAssignmentController.php:12-69", "app/Http/Controllers/AssetInspectionController.php:12-49", "app/Http/Controllers/AssetMaintenanceController.php:12-52", "app/Http/Controllers/AssetOwnershipController.php:12-44", "app/Http/Controllers/AssetScanEventController.php:12-45"],
        "refusal_followup_tombstone": ["routes/emar.php:340-348", "app/Http/Controllers/Emar/RefusalFollowUpController.php:17-108"],
        "shadowed_competency_create": ["routes/training.php:69-82"],
        "shift_incident_uri_mismatch": ["routes/incidents.php:115-118", "resources/js/pages/operations/shifts/show.tsx:3659-3661"],
        "compliance_export_selectors": ["app/Http/Controllers/Hr/ComplianceExportController.php:22-58", "app/Http/Controllers/Hr/ComplianceExportController.php:62-84", "app/Http/Controllers/Hr/ComplianceExportController.php:87-108", "app/Http/Controllers/Hr/ComplianceExportController.php:111-131", "app/Http/Controllers/Hr/ComplianceExportController.php:133-157"],
        "public_reference": ["routes/web.php:106-107", "app/Http/Controllers/Careers/ReferenceController.php:28-87"],
        "email_verification": ["vendor/laravel/fortify/routes/routes.php:85-96"],
        "client_funds": ["routes/operations.php:1151-1158", "app/Http/Controllers/Operations/ClientFundController.php:20-211"],
        "roadmap_shared_imports": ["resources/js/pages/Roadmap/Decisions.tsx:28", "resources/js/pages/Roadmap/Initiatives.tsx:25", "resources/js/pages/Roadmap/Quarterly/Index.tsx:24", "resources/js/pages/Roadmap/Quarterly/Show.tsx:24", "resources/js/pages/Roadmap/Suggestions.tsx:30", "resources/js/pages/Roadmap/shared.ts"],
        "site_vendor_credential_dialog_shared_imports": ["resources/js/pages/sites/vendors/_dialogs.tsx:42", "resources/js/pages/sites/credentials/_dialogs.tsx:56", "resources/js/pages/sites/vendors-credentials/global.tsx:54", "resources/js/pages/sites/vendors-credentials/_audit-dialog.tsx:36", "resources/js/pages/sites/vendors-credentials/_manage-types-dialog.tsx:30", "resources/js/pages/sites/_dialog-shared.tsx"],
    },
    "counts": {
        "routes": {group: sum(len(row["route_ids"]) for row in rows) for group, rows in route_groups.items()},
        "pages": {group: sum(len(row["page_ids"]) for row in rows) for group, rows in page_groups.items()},
        "route_total": len(route_seen),
        "page_total": len(page_seen),
        "route_target_relations": route_relation_count,
        "page_target_relations": page_relation_count,
        "medical_reachability_challenges": 8,
        "other_page_only_denominator_challenges": 0,
    },
    "validation": {
        "route_coverage": "197/197",
        "page_coverage": "63/63",
        "duplicate_routes": 0,
        "duplicate_pages": 0,
        "missing_routes": 0,
        "missing_pages": 0,
        "all_target_ids_exist_in_manifest": True,
        "medical_orphan_pages": ["PAGE-0038", "PAGE-0590"],
    },
    "checksums": {
        "coverage_sha256": coverage_sha,
        "coverage_recipe": "audited commit, denominator, sorted accepted IDs, then sorted ROUTE-ID|disposition and PAGE-ID|disposition; LF; no terminal LF; UTF-8 SHA-256",
    },
}

OUTPUT.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT), "sha256": hashlib.sha256(OUTPUT.read_bytes()).hexdigest(), "coverage_sha256": coverage_sha, "routes": len(route_seen), "pages": len(page_seen)}, indent=2))
