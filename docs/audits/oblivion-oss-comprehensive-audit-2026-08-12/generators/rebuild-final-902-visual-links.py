#!/usr/bin/env python3
"""Reconcile retained visual observations to the corrected 902-target register.

This preserves every observation and classification. It assigns a final feature
only when exact identity, one-to-one source lineage, or exact target route/page
evidence proves a unique target. Shared envelopes remain unresolved.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
REPO = AUDIT.parents[2]
MATRIX = AUDIT / "05-browser-visual-coverage-matrix.csv"
MANIFEST = SOURCE / "working-capability-manifest-902.json"
INVENTORY = AUDIT / "inventory.json"
SUMMARY = SOURCE / "final-902-visual-link-generation-summary.json"
RAW_VIEWPORT_EVIDENCE = AUDIT / "evidence" / "browser" / "rendered-component-viewport-matrix.json"

EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
EXPECTED_MATRIX_SHA = "92d12eedaa2e7593cc1ad0bc829f22d57aed36ded55ccfca8d919865f407e1a6"
EXPECTED_PRE_NORMALIZATION_MATRIX_SHA = "ea5df8257d5c6e3fa0c816aecc7e4642ed0ee6f13c4a3f39dfa0f8c8a7b3596b"
EXPECTED_PRE_ENRICHMENT_RECONCILED_MATRIX_SHA = "9eefb83821f12baee89d5721ea7c3318c03f35b6ffc53d0b597b1c466f4c4f0c"
EXPECTED_RECONCILED_MATRIX_SHA = "bda2192ff8a9d9aa1ea07acf83230efa4a4cd9edc3f08591dbc5f4c3fd739896"
EXPECTED_PRE_GLOBAL_EXACT_MATRIX_SHA = "d8aaf715968a3d974fd90cb8fac3365f0a18f3e575c2787d5098dc9771de4dcf"
EXPECTED_PRE_SPLIT_GLOBAL_MATRIX_SHA = "807ec6a27494b0e911e02e3989a2e26777eba6fd02fc88c6efa158511293bcd7"
EXPECTED_FINAL_902_MATRIX_SHA = "6e3dd441d97c77dad44adc6f20d370f2d7e849c65deb746f31bd309b4302de60"
EXPECTED_MATERIAL_ROUTE_WAVE_MATRIX_SHA = "66f4e8e4258e22f839575b086808b439db0ce526feefebae29444edb884eb2a2"
EXPECTED_FINAL_902_RELINKED_MATRIX_SHA = "f54ba4b4e0e8c4c4fe98d5c0541bbdebaa03b8ba254c6bf65ffecddd0b4358ef"
EXPECTED_VISUAL_WAVE3_MATRIX_SHA = "be4c1d262f6d7fd105de2587e6ae60e6b169e14a9d6042d253eb19408d54d454"
EXPECTED_POST_BVIS0011_MATRIX_SHA = "a8a1cdaf446a9939d789a65cdcd6c1765b45c17a43049277312a8c59f3a86e90"
EXPECTED_VISUAL_WAVE4_MATRIX_SHA = "30865b643891702ce79c2e9eb903319277569a007ba4e3d1e1e7e95c96f46274"
EXPECTED_PRE_REQUIRED_VIEWPORT_RESTORE_MATRIX_SHA = "5e1f633e955d2859131793e8ffe1809810e5343484c839e5f8b922cd437fc6aa"
EXPECTED_VISUAL_WAVE5_MATRIX_SHA = "b64a9e24649023442c0fda4c6c7e76a5aa6a5ad77a9248a33315bd10ee252474"
EXPECTED_VISUAL_WAVE6_MATRIX_SHA = "ebd28cc9a17e4094097ea55c993646f72c9f1e0926fa981e52309392dfc55a33"
EXPECTED_VISUAL_WAVE7_MATRIX_SHA = "01e0954e89f89f0fc369a70aaeb4ea4e02c129e9ad91c1df344133c157982868"
EXPECTED_VISUAL_WAVE8_MATRIX_SHA = "2202a20ee7fa7392b064078059edffeb21250fa0a03c0c431894087696ead544"
EXPECTED_VISUAL_WAVE9_MATRIX_SHA = "c087e68c203d7580178e995be2bc187be732ddc67ff37aa37573ba14ea6b54d1"
EXPECTED_VISUAL_WAVE10_MATRIX_SHA = "d136093400ccee540c71e2ab9568a233f8450f1e5ad09d3aef92028ada14f1ae"
EXPECTED_PAGE_REFRESH_MATRIX_SHA = "2aecabe3f7b1fed2a377328e08fbc3e674fa6b91adff703e6558d1658e87210b"
EXPECTED_VISUAL_WAVE11_MATRIX_SHA = "e24784347a4e24d4712b6f531ee25e06f976599cf7f36f128fb0d5de217fdb53"
EXPECTED_VISUAL_WAVE12_MATRIX_SHA = "f0aed8a6cbc242651ef7cd702685f8c948af276b3830d4d5960ea6ece1e9f363"
EXPECTED_MANIFEST_SHA = "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9"
EXPECTED_INVENTORY_SHA = "36617a5e08ef4c5aceff2a1a50a09d762506cc1ebfe927d2ee688abd8ef080ce"
EXPECTED_RAW_VIEWPORT_EVIDENCE_SHA = "809434eef85db02ac19f39caf14ff62b0a2bb1b8248ee69cc7a50003944a84d2"
PAGE_REFRESH_PRESERVE_EXACT_PROOF_IDS = {
    "VIS-002516", "VIS-002518", "VIS-002519", "VIS-002520", "VIS-002981",
    "VIS-003022", "VIS-003296", "VIS-003297", "VIS-003633",
}

# Independently reviewed Wave-11 material-state ownership. The legacy family
# does not name the current owner, so promotion is allowed only because every
# exact route in this row contains one common manifest target and no other.
# This is final-ID lineage only; the row remains Not safely reproducible.
WAVE11_ALL_ROUTE_INTERSECTION_PROOFS = {
    "VIS-020353": {
        "target": "CAP-OPS-ROSTERING-PLANNING",
        "prior_candidates": ["CAP-OPS-CLIENT-CALENDAR", "OPS-CALENDAR-SYNC"],
        "route_ids": ["ROUTE-2143", "ROUTE-2144", "ROUTE-2145"],
        "route_names": [
            "operations.rostering.calendar.events",
            "operations.rostering.calendar.shifts.store",
            "operations.rostering.calendar.shifts.update",
        ],
        "route_paths": [
            "operations/rostering/calendar/events",
            "operations/rostering/calendar/shifts",
            "operations/rostering/calendar/shifts/{shift}",
        ],
        "component_anchor": "app/Http/Controllers/CalendarController.php",
        "controller_sha256": "d3b23e85ad91366de4a28db85b42517e87c9f42d1f15fa27e7ff24ee00e57a40",
    },
}

# Independently reviewed Wave-12 safe-route ownership. The recorded page anchor
# conflicts with the exact route and is explicitly rejected as ownership
# evidence; only the unique route/controller/manifest relation is credited.
WAVE12_EXACT_ROUTE_REJECTED_PAGE_PROOFS = {
    "VIS-002282": {
        "target": "CAP-OPS-ROSTERING-PLANNING",
        "prior_candidates": ["CAP-OPS-CLIENT-CALENDAR", "OPS-CALENDAR-SYNC"],
        "route_id": "ROUTE-2143",
        "route_name": "operations.rostering.calendar.events",
        "route_path": "operations/rostering/calendar/events",
        "route_action": "App\\Http\\Controllers\\CalendarController@events",
        "rejected_page_id": "PAGE-0665",
        "rejected_page_target": "OPS-REPORT",
        "rejected_component_anchor": "operations/reports/Index",
        "controller_source": "app/Http/Controllers/CalendarController.php",
        "controller_sha256": "d3b23e85ad91366de4a28db85b42517e87c9f42d1f15fa27e7ff24ee00e57a40",
    },
}
LOCATION_RE = re.compile(r"^(.+\.(?:tsx|jsx|ts|js|vue)):(\d+)(?::\d+|-\d+)?$")
OVERLAY_LOCATION_RE = re.compile(r"^(.+\.(?:tsx|jsx)):(\d+):(\d+)$")
COMPONENT_DECL_RE = re.compile(
    r"^(?:export\s+)?(?:default\s+)?function\s+"
    r"([A-Za-z_$][\w$]*(?:Dialog|Modal|Wizard|Popover|Sheet))\s*\("
)
DIRECT_MUTATION_RE = re.compile(
    r"""\b(?:router|form)\.(post|put|patch|delete)\s*\(\s*([`'"])(.*?)\2""",
    re.DOTALL,
)
INDIRECT_MUTATION_RE = re.compile(
    r"\b(?:submitEmarMutation|axios\.(?:post|put|patch|delete)|fetch|form\.submit)\s*\("
)
DELEGATED_MUTATION_RE = re.compile(r"\bonAction\s*\(")
TEMPLATE_EXPR_RE = re.compile(r"\$\{[^{}]+\}")

# Independently source-reviewed custom-component ownership wave. Each tuple is
# target, component name, component source, component source lines, route IDs.
WAVE5_CUSTOM_COMPONENT_PROOFS = {
    "VIS-003790": ("CAP-MED-CLIENT-DOSE-ADMINISTRATION", "EmarRecordDialog", "resources/js/components/clients/profile/emar-dialog.tsx", "79-391", ["ROUTE-2021"]),
    "VIS-003794": ("CAP-HS-SITE-HAZARD-REGISTER", "HazardCreateDialog", "resources/js/components/health-safety/hazard-create-dialog.tsx", "64-429", ["ROUTE-2801"]),
    "VIS-003799": ("CAP-HS-HAZARDOUS-SUBSTANCE-STORAGE", "SiteAddStorageDialog", "resources/js/components/health-safety/site-chemical-storage-dialog.tsx", "22-111", ["ROUTE-1235"]),
    "VIS-003821": ("CAP-IT-SUPPORT-TICKET", "CreateTicketWizard", "resources/js/components/it/it-wizards.tsx", "165-348", ["ROUTE-1866"]),
    "VIS-003822": ("CAP-IT-PROVISIONING-REQUEST", "FulfilRequestDialog", "resources/js/components/it/it-wizards.tsx", "358-461", ["ROUTE-1865"]),
    "VIS-003867": ("CAP-CR-SIGNAL-TO-INCIDENT", "FlagIncidentDialog", "resources/js/components/control-room/flag-incident-dialog.tsx", "37-171", ["ROUTE-0267"]),
    "VIS-003926": ("CAP-MED-MEDICATION-ORDER-LIFECYCLE", "AddMedicationDialog", "resources/js/pages/emar/_dialogs.tsx", "30-258", ["ROUTE-0385"]),
    "VIS-003927": ("CAP-MED-INR-MONITORING", "RecordInrDialog", "resources/js/pages/emar/components/mar-governance-dialogs.tsx", "72-134", ["ROUTE-0344"]),
    "VIS-003928": ("CAP-MED-SYRINGE-DRIVER-MONITORING", "SyringeDriverDialog", "resources/js/pages/emar/components/mar-governance-dialogs.tsx", "137-224", ["ROUTE-0346"]),
    "VIS-003929": ("CAP-MED-CLIENT-ALERT-CONFIGURATION", "ManageAlertsDialog", "resources/js/pages/emar/components/mar-governance-dialogs.tsx", "227-345", ["ROUTE-0339"]),
    "VIS-003930": ("CAP-MED-MEDICATION-ORDER-VERIFICATION", "VerifyOrderDialog", "resources/js/pages/emar/components/mar-governance-dialogs.tsx", "348-415", ["ROUTE-0389", "ROUTE-0390"]),
    "VIS-003931": ("CAP-MED-ADMINISTRATION-CORRECTION-DECISION", "CorrectionsReviewDialog", "resources/js/pages/emar/components/mar-governance-dialogs.tsx", "418-497", ["ROUTE-0359", "ROUTE-0360"]),
    "VIS-004017": ("CAP-FIN-AUDIT-EXPORT-PACKAGE", "AuditExportDialog", "resources/js/components/finance/audit-export-dialog.tsx", "51-234", ["ROUTE-0471"]),
    "VIS-004045": ("CAP-FIN-DONOR-FUND-TRANSACTIONS", "DonorFundTransactionDialog", "resources/js/components/finance/donor-fund-transaction-dialog.tsx", "50-300", ["ROUTE-0546", "ROUTE-0547"]),
    "VIS-004051": ("CAP-ASSET-FIXED-ASSET-REGISTER", "FixedAssetDialog", "resources/js/components/finance/fixed-asset-dialog.tsx", "75-371", ["ROUTE-0566", "ROUTE-0568"]),
    "VIS-004052": ("CAP-ASSET-FIXED-ASSET-DISPOSAL", "FixedAssetDisposeDialog", "resources/js/components/finance/fixed-asset-dispose-dialog.tsx", "49-225", ["ROUTE-0569"]),
    "VIS-004082": ("CAP-FIN-ACCOUNTS-RECEIVABLE-ALLOCATION", "PaymentDialog", "resources/js/pages/finance/receivables/Index.tsx", "67-142", ["ROUTE-0673"]),
    "VIS-004095": ("CAP-FLEET-INCIDENT-RECORD-EVIDENCE", "FleetIncidentReportDialog", "resources/js/components/fleet/fleet-incident-report-dialog.tsx", "184-750", ["ROUTE-0750"]),
    "VIS-004101": ("CAP-FLEET-RESIDENT-TRACKING-ASSIGNMENT", "AssignTrackerDialog", "resources/js/pages/fleet-assets/resident-tracking/index.tsx", "485-743", ["ROUTE-0817", "ROUTE-0819"]),
    "VIS-004127": ("CAP-HS-EMERGENCY-DRILL-LIFECYCLE", "DrillDetailDialog", "resources/js/components/health-safety/drill-detail-dialog.tsx", "98-240", ["ROUTE-1095"]),
    "VIS-004128": ("CAP-HS-EMERGENCY-DRILL-LIFECYCLE", "DrillCompleteDialog", "resources/js/components/health-safety/drill-complete-dialog.tsx", "47-267", ["ROUTE-1091"]),
    "VIS-004133": ("CAP-HS-INJURY-CAPACITY-LIFECYCLE", "InjuryWizardDialog", "resources/js/components/health-safety/injury-wizard-dialog.tsx", "89-405", ["ROUTE-1128", "ROUTE-1130"]),
    "VIS-004134": ("CAP-HS-INJURY-CAPACITY-LIFECYCLE", "InjuryDetailDialog", "resources/js/components/health-safety/injury-detail-dialog.tsx", "63-195", ["ROUTE-1136"]),
    "VIS-004150": ("CAP-HS-PROCEDURE-AUTHORING-EVIDENCE", "ProcedureWizardDialog", "resources/js/components/health-safety/procedure-wizard-dialog.tsx", "179-520", ["ROUTE-1178", "ROUTE-1180"]),
    "VIS-004153": ("CAP-HS-RESTRAINT-EVENTS", "RestraintEventWizard", "resources/js/components/health-safety/restraint-event-wizard.tsx", "114-560", ["ROUTE-1201"]),
    "VIS-004154": ("CAP-HS-BEHAVIOUR-SUPPORT-PLANS", "BspWizard", "resources/js/components/health-safety/bsp-wizard.tsx", "69-325", ["ROUTE-1208"]),
    "VIS-004157": ("CAP-HS-HAZARDOUS-SUBSTANCE-REGISTER", "SubstanceDetailDialog", "resources/js/components/health-safety/substance-detail-dialog.tsx", "284-392", ["ROUTE-1234"]),
    "VIS-004158": ("CAP-HS-HAZARDOUS-SUBSTANCE-REGISTER", "SubstanceWizardDialog", "resources/js/components/health-safety/substance-wizard-dialog.tsx", "185-466", ["ROUTE-1227", "ROUTE-1229"]),
    "VIS-004181": ("CAP-HR-BENEFITS-ENROLLMENT", "BenefitsEnrollDialog", "resources/js/components/hr/benefits-enroll-dialog.tsx", "139-872", ["ROUTE-1323", "ROUTE-1324"]),
    "VIS-004182": ("CAP-HR-BENEFITS-ENROLLMENT", "BenefitsEnrollDialog", "resources/js/components/hr/benefits-enroll-dialog.tsx", "139-872", ["ROUTE-1323", "ROUTE-1324"]),
    "VIS-004219": ("CAP-HR-IMPORT-EXPORT-EMPLOYEE-IMPORT", "ImportWizard", "resources/js/pages/hr/import-export/index.tsx", "80-311", ["ROUTE-1484"]),
    "VIS-004261": ("CAP-INC-INCIDENT-FOLLOWUP", "IncidentDetailDialog", "resources/js/components/incidents/incident-detail-dialog.tsx", "156-258", ["ROUTE-1850"]),
    "VIS-004262": ("CAP-INC-INCIDENT-AUTHOR", "IncidentReportDialog", "resources/js/components/incidents/incident-report-dialog.tsx", "58-258", ["ROUTE-1839"]),
    "VIS-004263": ("CAP-INC-INCIDENT-FOLLOWUP", "IncidentDetailDialog", "resources/js/components/incidents/incident-detail-dialog.tsx", "156-258", ["ROUTE-1850"]),
    "VIS-004335": ("CAP-INC-SAFEGUARDING-CONCERN-INTAKE", "SafeguardingRaiseWizard", "resources/js/components/safeguarding/raise-wizard.tsx", "77-404", ["ROUTE-2503"]),
    "VIS-004337": ("CAP-SEC-QUECLINK-HUB-DEVICE-CUSTODY", "BulkActionDialog", "resources/js/pages/security-devices/integrations/queclink-hub.tsx", "1295-1477", ["ROUTE-2569"]),
    "VIS-004338": ("CAP-SEC-QUECLINK-HUB-PRESETS-SETTINGS", "SavePresetDialog", "resources/js/pages/security-devices/integrations/queclink-hub.tsx", "1699-1802", ["ROUTE-2586"]),
    "VIS-004436": ("CAP-SITE-SITE-CREDENTIAL-LIFECYCLE", "DeleteCredentialDialog", "resources/js/pages/sites/credentials/_dialogs.tsx", "946-1007", ["ROUTE-2765"]),
    "VIS-004437": ("CAP-SITE-SITE-CREDENTIAL-REVEAL-TOTP", "RemoveTotpDialog", "resources/js/pages/sites/credentials/_dialogs.tsx", "1011-1064", ["ROUTE-2772"]),
}

# Independently source-reviewed exact component/callback ownership wave. Values:
# target, proposed status, classification, prior candidates, anchor, source,
# source scope, source SHA-256, route IDs, required binding tokens.
WAVE6_EXACT_COMPONENT_PROOFS = {
    "VIS-003863": ("CAP-CR-BROADCAST-COMPOSE-SEND", "custom_component_exact_route_actions", "Source-inferred", "CAP-CR-BROADCAST-COMPOSE-SEND|CAP-CR-BROADCAST-OVERSIGHT", "resources/js/pages/control-room/broadcast.tsx:280:17", "resources/js/components/control-room/broadcast-wizard.tsx", "function BroadcastWizard:68-307", "31e2c40a8d364f705f7f7970c5672023102695e331a13c83d16d41d2382dbc52", ["ROUTE-0249"], ["function BroadcastWizard", "router.post"]),
    "VIS-004018": ("CAP-FIN-AUDIT-EXPORT-PACKAGE", "component_callback_exact_route_action", "Blocked", "CAP-FIN-AUDIT-EXPORT-DOWNLOAD|CAP-FIN-AUDIT-EXPORT-PACKAGE", "resources/js/pages/finance/audit-exports/Index.tsx:246:13", "resources/js/pages/finance/audit-exports/Index.tsx", "function AuditExportsIndex:85-266; onConfirm={confirmDelete}:262; confirmDelete:90", "eaebdb846f9b2640a8c6d6eb309fefe01a33cd9950cc5f7e736090782da30d7d", ["ROUTE-0472"], ["confirmDelete", "onConfirm={confirmDelete}"]),
    "VIS-004030": ("CAP-FIN-CONSOLIDATION-RUNS", "custom_component_exact_route_actions", "Source-inferred", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:331:33", "resources/js/pages/finance/Consolidation/Show.tsx", "function RunConsolidationDialog:211-272", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0524"], ["function RunConsolidationDialog", "post(`/finance/consolidation/"]),
    "VIS-004031": ("CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION", "custom_component_exact_route_actions", "Source-inferred", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:381:29", "resources/js/pages/finance/Consolidation/Show.tsx", "function AddEntityDialog:102-209", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0520"], ["function AddEntityDialog", "post(`/finance/consolidation/"]),
    "VIS-004032": ("CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION", "component_callback_exact_route_action", "Blocked", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:509:13", "resources/js/pages/finance/Consolidation/Show.tsx", "function ConsolidationShow:282-530; onConfirm={confirmRemoveEntity}:526; confirmRemoveEntity:292", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0521"], ["confirmRemoveEntity", "onConfirm={confirmRemoveEntity}"]),
    "VIS-004058": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "custom_component_exact_route_actions", "Source-inferred", "CAP-FIN-ACCOUNTING-INTEGRATION-ACCOUNT-MAPPING|CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE|CAP-FIN-ACCOUNTING-INTEGRATION-SYNC", "resources/js/pages/finance/Integrations/Index.tsx:408:34", "resources/js/pages/finance/Integrations/Index.tsx", "function CreateIntegrationDialog:111-199", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", ["ROUTE-0587"], ["function CreateIntegrationDialog", "post('/finance/integrations'"]),
    "VIS-004059": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "custom_component_exact_route_actions", "Source-inferred", "CAP-FIN-ACCOUNTING-INTEGRATION-ACCOUNT-MAPPING|CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE|CAP-FIN-ACCOUNTING-INTEGRATION-SYNC", "resources/js/pages/finance/Integrations/Index.tsx:422:33", "resources/js/pages/finance/Integrations/Index.tsx", "function CreateIntegrationDialog:111-199", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", ["ROUTE-0587"], ["function CreateIntegrationDialog", "post('/finance/integrations'"]),
    "VIS-004078": ("CAP-FIN-PURCHASE-ORDER-LIFECYCLE", "component_callback_exact_route_action", "Blocked", "CAP-FIN-PURCHASE-ORDER-BILL-CONVERSION|CAP-FIN-PURCHASE-ORDER-LIFECYCLE", "resources/js/pages/finance/purchase-orders/Show.tsx:256:13", "resources/js/pages/finance/purchase-orders/Show.tsx", "function PurchaseOrderShow:66-276; onConfirm={handleApprove}:263; handleApprove:76", "856d2eb597040fb0cb6d8bca0d6fc119073b949c863be8a29e1247346901ed73", ["ROUTE-0657"], ["handleApprove", "onConfirm={handleApprove}"]),
    "VIS-004079": ("CAP-FIN-PURCHASE-ORDER-BILL-CONVERSION", "component_callback_exact_route_action", "Blocked", "CAP-FIN-PURCHASE-ORDER-BILL-CONVERSION|CAP-FIN-PURCHASE-ORDER-LIFECYCLE", "resources/js/pages/finance/purchase-orders/Show.tsx:265:13", "resources/js/pages/finance/purchase-orders/Show.tsx", "function PurchaseOrderShow:66-276; onConfirm={handleConvertToBill}:272; handleConvertToBill:84", "856d2eb597040fb0cb6d8bca0d6fc119073b949c863be8a29e1247346901ed73", ["ROUTE-0658"], ["handleConvertToBill", "onConfirm={handleConvertToBill}"]),
    "VIS-004090": ("CAP-FLEET-VEHICLE-BOOKING-REQUEST", "component_callback_exact_route_action", "Source-inferred", "CAP-FLEET-VEHICLE-BOOKING-CHECKOUT-RETURN|CAP-FLEET-VEHICLE-BOOKING-DECISION|CAP-FLEET-VEHICLE-BOOKING-REQUEST", "resources/js/pages/fleet-assets/bookings/show.tsx:451:17", "resources/js/pages/fleet-assets/bookings/show.tsx", "function BookingShow:74-490; inline onConfirm:454", "3025c8b8a3160edd9ac4be12ae3e4908e577ea486290df2d0c742c05c56e100c", ["ROUTE-0716"], ["onConfirm={() =>", "router.post"]),
    "VIS-004091": ("CAP-FLEET-DEVICE-TRACKING-CONSENT", "component_callback_exact_route_action", "Blocked", "CAP-FLEET-DEVICE-REGISTRY-PAIRING|CAP-FLEET-DEVICE-TRACKING-CONSENT", "resources/js/pages/fleet-assets/devices/index.tsx:756:25", "resources/js/pages/fleet-assets/devices/index.tsx", "function DevicesIndex:221-1136; onConfirm={handleRevoke}:759; handleRevoke:364", "18e8632d443d6ca60661c723bc17f9085a084a4b52dbfd637c2d61805513c25f", ["ROUTE-0727"], ["handleRevoke", "onConfirm={handleRevoke}"]),
    "VIS-004092": ("CAP-FLEET-DEVICE-REGISTRY-PAIRING", "component_callback_exact_route_action", "Source-inferred", "CAP-FLEET-DEVICE-REGISTRY-PAIRING|CAP-FLEET-DEVICE-TRACKING-CONSENT", "resources/js/pages/fleet-assets/devices/index.tsx:1107:33", "resources/js/pages/fleet-assets/devices/index.tsx", "function DevicesIndex:221-1136; inline onConfirm:1110", "18e8632d443d6ca60661c723bc17f9085a084a4b52dbfd637c2d61805513c25f", ["ROUTE-0728"], ["onConfirm={() =>", "router.post"]),
    "VIS-004102": ("CAP-FLEET-TRIP-LIFECYCLE", "component_callback_exact_route_action", "Source-inferred", "CAP-FLEET-TRIP-LIFECYCLE|CAP-FLEET-TRIP-PLAYBACK", "resources/js/pages/fleet-assets/trips/playback.tsx:321:13", "resources/js/pages/fleet-assets/trips/playback.tsx", "function FleetTripPlayback:53-340; onConfirm={handleClose}:324; handleClose:81", "22a5c979b676e5606feec60dbaa2b4132dc15e28bbe99f5f07d7daa81c68a1f1", ["ROUTE-0849"], ["handleClose", "onConfirm={handleClose}"]),
    "VIS-004103": ("CAP-FLEET-TRIP-LIFECYCLE", "component_callback_exact_route_action", "Source-inferred", "CAP-FLEET-TRIP-LIFECYCLE|CAP-FLEET-TRIP-PLAYBACK", "resources/js/pages/fleet-assets/trips/playback.tsx:330:13", "resources/js/pages/fleet-assets/trips/playback.tsx", "function FleetTripPlayback:53-340; onConfirm={handleDelete}:333; handleDelete:89", "22a5c979b676e5606feec60dbaa2b4132dc15e28bbe99f5f07d7daa81c68a1f1", ["ROUTE-0846"], ["handleDelete", "onConfirm={handleDelete}"]),
    "VIS-004169": ("CAP-HR-CALENDAR-EVENT-MANAGEMENT", "custom_component_exact_route_actions", "Source-inferred", "CAP-HR-CALENDAR-EVENT-MANAGEMENT|CAP-HR-CALENDAR-FEED|CAP-HR-CALENDAR-PARTICIPATION", "resources/js/pages/hr/calendar/index.tsx:879:21", "resources/js/components/hr/calendar/event-wizard-dialog.tsx", "function EventWizardDialog:188-989", "788a105f03dc7692209b8d5f7919e8b77be1aaab1401fc2586e68f1c85fc7637", ["ROUTE-1297", "ROUTE-1299", "ROUTE-1300", "ROUTE-1301", "ROUTE-1302"], ["function EventWizardDialog", "form.post"]),
    "VIS-004170": ("CAP-HR-ICAL-TOKEN", "custom_component_exact_route_actions", "Source-inferred", "CAP-HR-CALENDAR-EVENT-MANAGEMENT|CAP-HR-CALENDAR-FEED|CAP-HR-CALENDAR-PARTICIPATION", "resources/js/pages/hr/calendar/index.tsx:892:17", "resources/js/components/hr/calendar/ical-subscribe-dialog.tsx", "function ICalSubscribeDialog:21-138", "cc859a3201acfb29031a720d3859d1591c800e69e49ed146e42bd85b455f8ec2", ["ROUTE-1481"], ["function ICalSubscribeDialog", "router.post"]),
    "VIS-004172": ("CAP-HR-CALENDAR-EVENT-MANAGEMENT", "component_callback_exact_route_action", "Source-inferred", "CAP-HR-CALENDAR-EVENT-MANAGEMENT|CAP-HR-CALENDAR-FEED|CAP-HR-CALENDAR-PARTICIPATION", "resources/js/pages/hr/calendar/index.tsx:944:21", "resources/js/pages/hr/calendar/index.tsx", "function CalendarIndex:188-1028 + function QuickAddPopover:1243-1326; inline onCreate callback:949", "a0e00dec21bf6f91ed218da1b55aca1cc0012216480f9208891d283b0840104d", ["ROUTE-1299"], ["onCreate={", "router.post"]),
}

# Independently source-reviewed visual ownership wave. Values:
# target, status, classification, prior status/candidates, anchor, source,
# exact scope, source SHA-256, route IDs, and required source-binding tokens.
WAVE7_EXACT_COMPONENT_PROOFS = {
    "VIS-003296": ("CAP-SITE-PRODUCT-LIFECYCLE", "component_overlay_exact_route_actions", "Blocked", "unresolved_no_manifest_lineage", "", "resources/js/pages/catering/products/index.tsx:212:17", "resources/js/pages/catering/products/index.tsx", "CateringProductsIndex:34-299; Dialog:212-299; form onSubmit={submit}:217; submit:87-97", "d0367d47eaf992a7b48760e3d7f6b5da1120edf3f1e0e8634e1853710743ca96", ["ROUTE-0100", "ROUTE-0102"], ["function submit", "onSubmit={submit}", "form.post('/catering/products'", "form.put(`/catering/products/"]),
    "VIS-003297": ("CAP-SITE-DIETARY-TAG-LIFECYCLE", "component_overlay_exact_route_actions", "Blocked", "unresolved_no_manifest_lineage", "", "resources/js/pages/catering/tags/index.tsx:149:17", "resources/js/pages/catering/tags/index.tsx", "CateringTagsIndex:27-209; Dialog:149-209; form onSubmit={submit}:155; submit:57-64", "64c17ebf6e9d01351f7614e74e3d4b8c0520ecad8e2cc87a1c60d20a02cc455d", ["ROUTE-0111", "ROUTE-0113"], ["function submit", "onSubmit={submit}", "form.post('/catering/tags'", "form.put(`/catering/tags/"]),
    "VIS-003304": ("CAP-CR-INCIDENT-TO-ALERT", "component_callback_exact_route_action", "Blocked", "unresolved_split_family_page_ambiguous", "CAP-CR-INCIDENT-OVERSIGHT|CAP-CR-INCIDENT-TO-ALERT|CAP-CR-SIGNAL-TO-INCIDENT", "resources/js/pages/control-room/incidents.tsx:713:13", "resources/js/pages/control-room/incidents.tsx", "IncidentTracker:142-786; create-alert Dialog:713-766; Button onClick={submitCreateAlert}:761; submitCreateAlert:199-230", "a97885cdb2db31d97da8b26664cb776379edb4c88a527ee411cc6aa438681abb", ["ROUTE-0266"], ["submitCreateAlert", "onClick={submitCreateAlert}", "router.post"]),
    "VIS-003324": ("CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:123:9", "resources/js/pages/finance/Consolidation/Show.tsx", "AddEntityDialog:102-209; Dialog:123; DialogTrigger:124; form onSubmit={handleSubmit}:135; handleSubmit:112-120", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0520"], ["function AddEntityDialog", "onSubmit={handleSubmit}", "post(`/finance/consolidation/"]),
    "VIS-003325": ("CAP-FIN-CONSOLIDATION-RUNS", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:226:9", "resources/js/pages/finance/Consolidation/Show.tsx", "RunConsolidationDialog:211-272; Dialog:226; DialogTrigger:227; form onSubmit={handleSubmit}:238; handleSubmit:218-223", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0524"], ["function RunConsolidationDialog", "onSubmit={handleSubmit}", "post(`/finance/consolidation/"]),
    "VIS-003333": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-ACCOUNTING-INTEGRATION-ACCOUNT-MAPPING|CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE|CAP-FIN-ACCOUNTING-INTEGRATION-SYNC", "resources/js/pages/finance/Integrations/Index.tsx:130:9", "resources/js/pages/finance/Integrations/Index.tsx", "CreateIntegrationDialog:111-199; Dialog:130; DialogTrigger:131; form onSubmit={handleSubmit}:144; handleSubmit:119-127", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", ["ROUTE-0587"], ["function CreateIntegrationDialog", "onSubmit={handleSubmit}", "post('/finance/integrations'"]),
    "VIS-003339": ("CAP-FIN-ACCOUNTS-RECEIVABLE-ALLOCATION", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-ACCOUNTS-RECEIVABLE-AGING|CAP-FIN-ACCOUNTS-RECEIVABLE-ALLOCATION|CAP-FIN-ACCOUNTS-RECEIVABLE-STATEMENTS", "resources/js/pages/finance/receivables/Index.tsx:335:49", "resources/js/pages/finance/receivables/Index.tsx", "ReceivablesIndex wrapper:335-368; PaymentDialog:67-142; DialogTrigger:341; form onSubmit={handleSubmit}:84; handleSubmit:75-81", "f068c71a80952adfdca55a645343e23ce8d4e944d01bd191094f7434a8e63fa8", ["ROUTE-0673"], ["function PaymentDialog", "onSubmit={handleSubmit}", "form.post('/finance/receivables/allocate'"]),
    "VIS-003342": ("CAP-FLEET-VEHICLE-BOOKING-DECISION", "component_callback_exact_route_action", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FLEET-VEHICLE-BOOKING-CHECKOUT-RETURN|CAP-FLEET-VEHICLE-BOOKING-DECISION|CAP-FLEET-VEHICLE-BOOKING-REQUEST", "resources/js/pages/fleet-assets/bookings/show.tsx:459:17", "resources/js/pages/fleet-assets/bookings/show.tsx", "BookingShow:74-490; reject AlertDialog:459-482; AlertDialogAction inline onClick:476-478", "3025c8b8a3160edd9ac4be12ae3e4908e577ea486290df2d0c742c05c56e100c", ["ROUTE-0718"], ["rejection_reason", "router.post(`/fleet-assets/bookings/"]),
    "VIS-003343": ("CAP-FLEET-DEVICE-REGISTRY-PAIRING", "component_callback_exact_route_action", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FLEET-DEVICE-REGISTRY-PAIRING|CAP-FLEET-DEVICE-TRACKING-CONSENT", "resources/js/pages/fleet-assets/devices/index.tsx:769:17", "resources/js/pages/fleet-assets/devices/index.tsx", "DevicesIndex:221-1136; pair Dialog:769-863; form onSubmit={handlePair}:778; handlePair:307-315", "18e8632d443d6ca60661c723bc17f9085a084a4b52dbfd637c2d61805513c25f", ["ROUTE-0730"], ["handlePair", "onSubmit={handlePair}", "router.post"]),
    "VIS-003495": ("CAP-RESP-STAY-LIFECYCLE", "component_callback_exact_route_action", "Source-inferred", "unresolved_no_manifest_lineage", "", "resources/js/pages/respite/stays/show.tsx:418:13", "resources/js/pages/respite/stays/show.tsx", "RespiteStayShow:42-462; extend Dialog:418-434; Button onClick={handleExtend}:431; handleExtend:48-53", "890a4b183985b014d67117b092ed96a4ea1077a18dd290da7413ba5c1c9ef3a6", ["ROUTE-2452"], ["handleExtend", "onClick={handleExtend}", "/extend"]),
    "VIS-003496": ("CAP-RESP-STAY-LIFECYCLE", "component_callback_exact_route_action", "Source-inferred", "unresolved_no_manifest_lineage", "", "resources/js/pages/respite/stays/show.tsx:437:13", "resources/js/pages/respite/stays/show.tsx", "RespiteStayShow:42-462; discharge Dialog:437-459; Button onClick={handleDischarge}:456; handleDischarge:55-60", "890a4b183985b014d67117b092ed96a4ea1077a18dd290da7413ba5c1c9ef3a6", ["ROUTE-2450"], ["handleDischarge", "onClick={handleDischarge}", "/discharge"]),
    "VIS-003507": ("CAP-SEC-QUECLINK-HUB-PRESETS-SETTINGS", "component_callback_exact_route_action", "Source-inferred", "unresolved_no_manifest_lineage", "", "resources/js/pages/security-devices/integrations/queclink-hub.tsx:1623:17", "resources/js/pages/security-devices/integrations/queclink-hub.tsx", "PresetsCard:1481-1697; apply Dialog:1623-1685; Button onClick={() => applyPreset(confirm)}:1675-1678; applyPreset:1497-1504", "6a4dd9b065afbebb2457100d9fea2b6026b2ea75690b70ceccfafc2645d20af4", ["ROUTE-2580"], ["applyPreset", "onClick={() => applyPreset(confirm)}", "/presets/"]),
    "VIS-003512": ("CAP-SET-API-CREDENTIAL-LIFECYCLE", "component_callback_exact_route_action", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-SET-API-CREDENTIAL-LIFECYCLE|CAP-SET-OUTBOUND-WEBHOOK-CONFIG", "resources/js/pages/settings/api.tsx:491:17", "resources/js/pages/settings/api.tsx", "Api:104-647; generate-key Dialog:491-562; Button onClick={handleGenerateKey}:528-532; handleGenerateKey:164-181", "8b7f11ef8c964e149c18eb2a0614213ba7dd5713562076dc5cd1728f2e97ee2d", ["ROUTE-2618"], ["handleGenerateKey", "onClick={handleGenerateKey}", "'/settings/api/keys', 'POST'"]),
    "VIS-003513": ("CAP-SET-OUTBOUND-WEBHOOK-CONFIG", "component_callback_exact_route_action", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-SET-API-CREDENTIAL-LIFECYCLE|CAP-SET-OUTBOUND-WEBHOOK-CONFIG", "resources/js/pages/settings/api.tsx:564:17", "resources/js/pages/settings/api.tsx", "Api:104-647; add-webhook Dialog:564-641; Button onClick={handleAddWebhook}:601-605; handleAddWebhook:207-224", "8b7f11ef8c964e149c18eb2a0614213ba7dd5713562076dc5cd1728f2e97ee2d", ["ROUTE-2620"], ["handleAddWebhook", "onClick={handleAddWebhook}", "'/settings/api/webhooks', 'POST'"]),
    "VIS-003516": ("CAP-SET-DATA-PRIVACY-COMPLIANCE-CONFIG", "component_callback_exact_route_action", "Source-inferred", "unresolved_no_manifest_lineage", "", "resources/js/pages/settings/data.tsx:1976:17", "resources/js/pages/settings/data.tsx", "Data:333-2402; DSAR Dialog:1976-2109; Button onClick={createDsarRequest}:2099-2103; createDsarRequest:648-675", "c744e72d478ad97f8bd4669af66f42972d845b40998be86576567b64e94da6ff", ["ROUTE-2648"], ["createDsarRequest", "onClick={createDsarRequest}", "'/settings/data/requests'"]),
    "VIS-003517": ("CAP-SET-DATA-PRIVACY-COMPLIANCE-CONFIG", "component_callback_exact_route_action", "Source-inferred", "unresolved_no_manifest_lineage", "", "resources/js/pages/settings/data.tsx:2112:17", "resources/js/pages/settings/data.tsx", "Data:333-2402; breach Dialog:2112-2247; Button onClick={createBreach}:2237-2241; createBreach:677-705", "c744e72d478ad97f8bd4669af66f42972d845b40998be86576567b64e94da6ff", ["ROUTE-2642"], ["createBreach", "onClick={createBreach}", "'/settings/data/breaches'"]),
    "VIS-003518": ("CAP-SET-DATA-PROCESSOR-REGISTER", "component_overlay_exact_route_actions", "Blocked", "unresolved_no_manifest_lineage", "", "resources/js/pages/settings/data.tsx:2250:17", "resources/js/pages/settings/data.tsx", "Data:333-2402; processor Dialog:2250-2398; Button onClick={submitProcessor}:2388-2392; submitProcessor:707-743", "c744e72d478ad97f8bd4669af66f42972d845b40998be86576567b64e94da6ff", ["ROUTE-2645", "ROUTE-2647"], ["submitProcessor", "onClick={submitProcessor}", "'/settings/data/processors'"]),
    "VIS-003633": ("CAP-SET-USER-ACCOUNT-LIFECYCLE", "component_callback_exact_route_action", "Blocked", "unresolved_no_manifest_lineage", "", "resources/js/pages/system/users/Index.tsx:556:13", "resources/js/pages/system/users/Index.tsx", "UsersIndex:113-576; delete Dialog:555-573; Button onClick={handleDelete}:568; handleDelete:147-152", "16e2b5c604b51a27effd1aa3c0bfcfcc9e8a248158b761f43f92f8dbd6592c57", ["ROUTE-2961"], ["handleDelete", "onClick={handleDelete}", "router.delete(`/system/users/"]),
    "VIS-003679": ("CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:124:13", "resources/js/pages/finance/Consolidation/Show.tsx", "AddEntityDialog:102-209; Dialog:123; DialogTrigger:124; form onSubmit={handleSubmit}:135; handleSubmit:112-120", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0520"], ["function AddEntityDialog", "onSubmit={handleSubmit}", "post(`/finance/consolidation/"]),
    "VIS-003680": ("CAP-FIN-CONSOLIDATION-RUNS", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-CONSOLIDATION-GROUP-CONFIGURATION|CAP-FIN-CONSOLIDATION-RUNS", "resources/js/pages/finance/Consolidation/Show.tsx:227:13", "resources/js/pages/finance/Consolidation/Show.tsx", "RunConsolidationDialog:211-272; Dialog:226; DialogTrigger:227; form onSubmit={handleSubmit}:238; handleSubmit:218-223", "2c9ea62968f9a05828ec2079ca2f61272a04e846a564370961dddb105d84940c", ["ROUTE-0524"], ["function RunConsolidationDialog", "onSubmit={handleSubmit}", "post(`/finance/consolidation/"]),
    "VIS-003688": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-ACCOUNTING-INTEGRATION-ACCOUNT-MAPPING|CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE|CAP-FIN-ACCOUNTING-INTEGRATION-SYNC", "resources/js/pages/finance/Integrations/Index.tsx:131:13", "resources/js/pages/finance/Integrations/Index.tsx", "CreateIntegrationDialog:111-199; Dialog:130; DialogTrigger:131; form onSubmit={handleSubmit}:144; handleSubmit:119-127", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", ["ROUTE-0587"], ["function CreateIntegrationDialog", "onSubmit={handleSubmit}", "post('/finance/integrations'"]),
    "VIS-003694": ("CAP-FIN-ACCOUNTS-RECEIVABLE-ALLOCATION", "component_overlay_exact_route_actions", "Source-inferred", "unresolved_split_family_page_ambiguous", "CAP-FIN-ACCOUNTS-RECEIVABLE-AGING|CAP-FIN-ACCOUNTS-RECEIVABLE-ALLOCATION|CAP-FIN-ACCOUNTS-RECEIVABLE-STATEMENTS", "resources/js/pages/finance/receivables/Index.tsx:341:53", "resources/js/pages/finance/receivables/Index.tsx", "ReceivablesIndex wrapper:335-368; PaymentDialog:67-142; DialogTrigger:341; form onSubmit={handleSubmit}:84; handleSubmit:75-81", "f068c71a80952adfdca55a645343e23ce8d4e944d01bd191094f7434a8e63fa8", ["ROUTE-0673"], ["function PaymentDialog", "onSubmit={handleSubmit}", "form.post('/finance/receivables/allocate'"]),
}

# Independently reviewed Wave-8 exact visual ownership. Values are target,
# proposed status, exact bounded source scope, proof source, proof-source SHA,
# exact route IDs and independently sorted controller actions.
WAVE8_EXACT_OVERLAY_PROOFS = {
    "VIS-003167": ("CAP-OPS-CLIENT-RECORD-LIFECYCLE", "component_overlay_exact_route_actions", "AddClientDialog:769; submit branches:897-900", "resources/js/components/clients/add-client-dialog.tsx", "eea45e09473dd4c15f6346581a3d7af72415b85656c8636e4530563ccb8a9567", ["ROUTE-1934", "ROUTE-1937"], ["App\\Http\\Controllers\\ClientController@store", "App\\Http\\Controllers\\ClientController@update"]),
    "VIS-003957": ("CAP-OPS-CLIENT-RECORD-LIFECYCLE", "custom_component_exact_route_actions", "AddClientDialog:769; submit branches:897-900", "resources/js/components/clients/add-client-dialog.tsx", "eea45e09473dd4c15f6346581a3d7af72415b85656c8636e4530563ccb8a9567", ["ROUTE-1934", "ROUTE-1937"], ["App\\Http\\Controllers\\ClientController@store", "App\\Http\\Controllers\\ClientController@update"]),
    "VIS-003788": ("CAP-CLIN-CLIENT-ABC-BEHAVIOUR-RECORD", "custom_component_exact_route_actions", "AbcEntryDialog:132; base:153; fetch:229; mutations:325,330,340", "resources/js/components/clients/profile/abc-dialog.tsx", "ee75cb961626a0af4dda41b140a3e20f101486670a90f35462b70a281ec70b79", ["ROUTE-0133", "ROUTE-0134", "ROUTE-0135", "ROUTE-0136"], ["App\\Http\\Controllers\\Clinical\\BehaviourAbcController@destroy", "App\\Http\\Controllers\\Clinical\\BehaviourAbcController@show", "App\\Http\\Controllers\\Clinical\\BehaviourAbcController@store", "App\\Http\\Controllers\\Clinical\\BehaviourAbcController@update"]),
    "VIS-003789": ("CAP-OPS-CARE-PLAN-AUTHORING", "custom_component_exact_route_actions", "CarePlanWizardDialog:372; submit branches:709-712", "resources/js/components/clients/profile/care-plan-dialog.tsx", "ecfdeb1f9f6514f68deba01ce3fb1403d60bf9752ea7805d17df1f1a2926cf2d", ["ROUTE-1906", "ROUTE-1909"], ["App\\Http\\Controllers\\Operations\\CarePlanController@store", "App\\Http\\Controllers\\Operations\\CarePlanController@update"]),
    "VIS-004429": ("CAP-SITE-SITE-VENDOR-LIFECYCLE", "custom_component_exact_route_actions", "AddVendorDialog:121; form.post:195", "resources/js/pages/sites/vendors/_dialogs.tsx", "fb3a259211306d5f9a59e85a261eee4cba88e17317e5309fbc5f683d05afee68", ["ROUTE-2889"], ["App\\Http\\Controllers\\Sites\\SiteVendorController@store"]),
    "VIS-004430": ("CAP-SITE-SITE-VENDOR-LIFECYCLE", "custom_component_exact_route_actions", "EditVendorDialog:254; form.put:330", "resources/js/pages/sites/vendors/_dialogs.tsx", "fb3a259211306d5f9a59e85a261eee4cba88e17317e5309fbc5f683d05afee68", ["ROUTE-2891"], ["App\\Http\\Controllers\\Sites\\SiteVendorController@update"]),
    "VIS-004432": ("CAP-SITE-SITE-VENDOR-LIFECYCLE", "custom_component_exact_route_actions", "DeleteVendorDialog:741; router.delete:757", "resources/js/pages/sites/vendors/_dialogs.tsx", "fb3a259211306d5f9a59e85a261eee4cba88e17317e5309fbc5f683d05afee68", ["ROUTE-2890"], ["App\\Http\\Controllers\\Sites\\SiteVendorController@destroy"]),
    "VIS-004433": ("CAP-SITE-SITE-CREDENTIAL-LIFECYCLE", "custom_component_exact_route_actions", "AddCredentialDialog:128; form.post:220", "resources/js/pages/sites/credentials/_dialogs.tsx", "d98c3c73213c27ffe6ca821c02d1e67100bc96884bf8ea117d18318b9a3fcc03", ["ROUTE-2764"], ["App\\Http\\Controllers\\Sites\\SiteCredentialController@store"]),
    "VIS-004434": ("CAP-SITE-SITE-CREDENTIAL-LIFECYCLE", "custom_component_exact_route_actions", "EditCredentialDialog:292; form.put:389", "resources/js/pages/sites/credentials/_dialogs.tsx", "d98c3c73213c27ffe6ca821c02d1e67100bc96884bf8ea117d18318b9a3fcc03", ["ROUTE-2766"], ["App\\Http\\Controllers\\Sites\\SiteCredentialController@update"]),
    "VIS-004438": ("CAP-SITE-SITE-VENDOR-GLOBAL-AUDIT", "custom_component_exact_route_actions", "AuditLogDialog:89; fetch GET:116-121", "resources/js/pages/sites/vendors-credentials/_audit-dialog.tsx", "fa5ab48b91c1e13dfb82e7791be701ba789f80feb02efa063b18d201e58d48cc", ["ROUTE-3022"], ["App\\Http\\Controllers\\Sites\\SiteVendorController@globalAudit"]),
    "VIS-004439": ("SITE-CREDENTIAL-TYPE", "custom_component_exact_route_actions", "ManageCredentialTypesDialog:51; fetch GET:74-83; fetch PUT:127-148", "resources/js/pages/sites/vendors-credentials/_manage-types-dialog.tsx", "ebf63e7f993274dbc0a2a006ce7946a067f0890e149680460138008ad524926b", ["ROUTE-0321", "ROUTE-0322"], ["App\\Http\\Controllers\\Sites\\CredentialTypeController@bulkSave", "App\\Http\\Controllers\\Sites\\CredentialTypeController@index"]),
    "VIS-004107": ("CAP-GOV-RESOLUTION-AUTHORING-EVIDENCE", "custom_component_exact_route_actions", "NewResolutionDialog:257; form.post:313", "resources/js/pages/Governance/Resolutions/_dialogs.tsx", "1e817707e6e5b8063d9aeb2350e6467b9952329bc4060374153fc4ccf3e4e0ca", ["ROUTE-0989"], ["App\\Domain\\Governance\\Http\\Controllers\\ResolutionController@store"]),
    "VIS-003375": ("CAP-GOV-RESOLUTION-AUTHORING-EVIDENCE", "component_overlay_exact_route_actions", "NewResolutionDialog:257; Dialog:266; form.post:313", "resources/js/pages/Governance/Resolutions/_dialogs.tsx", "1e817707e6e5b8063d9aeb2350e6467b9952329bc4060374153fc4ccf3e4e0ca", ["ROUTE-0989"], ["App\\Domain\\Governance\\Http\\Controllers\\ResolutionController@store"]),
    "VIS-003368": ("CAP-GOV-MEETING-SCHEDULING-AGENDA", "component_callback_exact_route_action", "agenda Dialog:470; submitAgendaItem:265-273", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0938"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@addAgendaItem"]),
    "VIS-003706": ("CAP-GOV-MEETING-SCHEDULING-AGENDA", "component_callback_exact_route_action", "agenda DialogTrigger:471; submitAgendaItem:265-273", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0938"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@addAgendaItem"]),
    "VIS-003369": ("CAP-GOV-MEETING-SCHEDULING-AGENDA", "component_callback_exact_route_action", "remove agenda AlertDialog:605; removeAgendaItem:319-323", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0939"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@removeAgendaItem"]),
    "VIS-003707": ("CAP-GOV-MEETING-SCHEDULING-AGENDA", "component_callback_exact_route_action", "remove agenda AlertDialogTrigger:606; removeAgendaItem:319-323", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0939"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@removeAgendaItem"]),
    "VIS-003370": ("CAP-GOV-MEETING-ATTENDANCE-RSVP", "component_callback_exact_route_action", "attendance Dialog:647; submitAttendance:276-294", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0941"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@recordAttendance"]),
    "VIS-003708": ("CAP-GOV-MEETING-ATTENDANCE-RSVP", "component_callback_exact_route_action", "attendance DialogTrigger:648; submitAttendance:276-294", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0941"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@recordAttendance"]),
    "VIS-003371": ("CAP-GOV-MEETING-MINUTES-SIGNOFF", "component_overlay_exact_route_actions", "minutes Dialog:750; submitMinutes:296-311", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0944", "ROUTE-0945"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@storeMinutes", "App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@updateMinutes"]),
    "VIS-003709": ("CAP-GOV-MEETING-MINUTES-SIGNOFF", "component_overlay_exact_route_actions", "minutes DialogTrigger:751; submitMinutes:296-311", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0944", "ROUTE-0945"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@storeMinutes", "App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@updateMinutes"]),
    "VIS-003372": ("CAP-GOV-MEETING-MINUTES-SIGNOFF", "component_callback_exact_route_action", "approve minutes AlertDialog:820; submitForApproval:313-317", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0946"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@approveMinutes"]),
    "VIS-003710": ("CAP-GOV-MEETING-MINUTES-SIGNOFF", "component_callback_exact_route_action", "approve minutes AlertDialogTrigger:821; submitForApproval:313-317", "resources/js/pages/Governance/Meetings/Show.tsx", "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46", ["ROUTE-0946"], ["App\\Domain\\Governance\\Http\\Controllers\\GovernanceMeetingController@approveMinutes"]),
    "VIS-003376": ("CAP-GOV-RISK-TREATMENTS-EVIDENCE", "component_callback_exact_route_action", "add treatment Dialog:191; submitTreatment:120-131", "resources/js/pages/Governance/Risks/Show.tsx", "a9e0389afbb76cc943550b9c3c8313f4211ea4b5dc251dc9942966e42bf92c31", ["ROUTE-1009"], ["App\\Domain\\Governance\\Http\\Controllers\\RiskRegisterController@addTreatment"]),
    "VIS-003711": ("CAP-GOV-RISK-TREATMENTS-EVIDENCE", "component_callback_exact_route_action", "add treatment DialogTrigger:192; submitTreatment:120-131", "resources/js/pages/Governance/Risks/Show.tsx", "a9e0389afbb76cc943550b9c3c8313f4211ea4b5dc251dc9942966e42bf92c31", ["ROUTE-1009"], ["App\\Domain\\Governance\\Http\\Controllers\\RiskRegisterController@addTreatment"]),
    "VIS-003377": ("CAP-GOV-RISK-ACCEPTANCE-CLOSURE", "component_callback_exact_route_action", "accept risk Dialog:254; submitAcceptance:133-144", "resources/js/pages/Governance/Risks/Show.tsx", "a9e0389afbb76cc943550b9c3c8313f4211ea4b5dc251dc9942966e42bf92c31", ["ROUTE-1005"], ["App\\Domain\\Governance\\Http\\Controllers\\RiskRegisterController@accept"]),
    "VIS-003712": ("CAP-GOV-RISK-ACCEPTANCE-CLOSURE", "component_callback_exact_route_action", "accept risk DialogTrigger:255; submitAcceptance:133-144", "resources/js/pages/Governance/Risks/Show.tsx", "a9e0389afbb76cc943550b9c3c8313f4211ea4b5dc251dc9942966e42bf92c31", ["ROUTE-1005"], ["App\\Domain\\Governance\\Http\\Controllers\\RiskRegisterController@accept"]),
    "VIS-003367": ("CAP-COMP-OBLIGATION-EVIDENCE", "component_callback_exact_route_action", "upload evidence Dialog:177; axios.post:120", "resources/js/pages/Governance/Compliance/Show.tsx", "fddc2d9b63c2b403e04d8c73efcea41d480826756ea656d7cbad739e9857ba5d", ["ROUTE-0907"], ["App\\Domain\\Governance\\Http\\Controllers\\ComplianceController@uploadEvidence"]),
    "VIS-003705": ("CAP-COMP-OBLIGATION-EVIDENCE", "component_callback_exact_route_action", "upload evidence DialogTrigger:178; axios.post:120", "resources/js/pages/Governance/Compliance/Show.tsx", "fddc2d9b63c2b403e04d8c73efcea41d480826756ea656d7cbad739e9857ba5d", ["ROUTE-0907"], ["App\\Domain\\Governance\\Http\\Controllers\\ComplianceController@uploadEvidence"]),
    "VIS-003334": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "component_callback_exact_route_action", "disconnect AlertDialog:359; handleDisconnect:219-221", "resources/js/pages/finance/Integrations/Index.tsx", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", ["ROUTE-0588"], ["App\\Domain\\Finance\\Http\\Controllers\\AccountingIntegrationController@destroy"]),
    "VIS-003689": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", "component_callback_exact_route_action", "disconnect AlertDialogTrigger:360; handleDisconnect:219-221", "resources/js/pages/finance/Integrations/Index.tsx", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", ["ROUTE-0588"], ["App\\Domain\\Finance\\Http\\Controllers\\AccountingIntegrationController@destroy"]),
    "VIS-003348": ("CAP-FLEET-CHECKLIST-TEMPLATE-DEFINITION", "component_overlay_exact_route_actions", "create-template Dialog:162; form onSubmit:167; handleCreateTemplate:88-96", "resources/js/pages/fleet-assets/maintenance/checklists/index.tsx", "76a1bc8957bfa6a3ef2b72e958cdc1185896821d6760afaa6262c733758f2b56", ["ROUTE-0773"], ["App\\Http\\Controllers\\FleetAssets\\ChecklistController@store"]),
    "VIS-003394": ("CAP-HR-CALENDAR-EVENT-MANAGEMENT", "component_callback_exact_route_action", "delete-event AlertDialog:1009; confirmDelete:536-544", "resources/js/pages/hr/calendar/index.tsx", "a0e00dec21bf6f91ed218da1b55aca1cc0012216480f9208891d283b0840104d", ["ROUTE-1300"], ["App\\Http\\Controllers\\Hr\\CalendarController@destroy"]),
    "VIS-003520": ("CAP-SET-SELF-ACCOUNT-DELETION", "component_overlay_exact_route_actions", "delete-account Dialog:1253; Form action:1277-1279", "resources/js/pages/settings/profile.tsx", "c57224df381f1a67ee6ea53c66583a70cc00133d569bfacc3f4a7dad793f03b1", ["ROUTE-2670"], ["App\\Http\\Controllers\\Settings\\ProfileController@destroy"]),
    "VIS-003750": ("CAP-SET-SELF-ACCOUNT-DELETION", "component_overlay_exact_route_actions", "delete-account DialogTrigger:1254; Form action:1277-1279", "resources/js/pages/settings/profile.tsx", "c57224df381f1a67ee6ea53c66583a70cc00133d569bfacc3f4a7dad793f03b1", ["ROUTE-2670"], ["App\\Http\\Controllers\\Settings\\ProfileController@destroy"]),
    "VIS-003499": ("CAP-SEC-DEVICE-GROUP-MEMBERSHIP", "component_callback_exact_route_action", "add-member Dialog:233; submitAdd:77-87", "resources/js/pages/security-devices/device-groups/show.tsx", "9aa2c4c93395dca346228466c36c821a7a452a0be5d76d9ffe479665219a889e", ["ROUTE-2539"], ["App\\Domain\\SecurityDevices\\Http\\Controllers\\DeviceGroupController@addMember"]),
    "VIS-003883": ("CAP-MED-CD-LOSS-INVESTIGATION-CLOSURE", "component_overlay_exact_route_actions", "LossActionDialog:393; fixed action union; submit:397", "resources/js/pages/emar/_cd-dialogs.tsx", "94175a4da26437d699ae2de89c506993e7dce964668c8457370407df0be9799d", ["ROUTE-0357", "ROUTE-0358"], ["App\\Http\\Controllers\\Emar\\CDLossReportController@investigate", "App\\Http\\Controllers\\Emar\\CDLossReportController@resolve"]),
    "VIS-003913": ("CAP-MED-STOCK-CONTROL", "component_overlay_exact_route_actions", "ReceiveStockDialog:179; submitEmarMutation literal:207", "resources/js/pages/emar/_stock-dialogs.tsx", "24bb6dfea869e3ef1494335b3261ca7b966187509676998d3d427716d7eb0758", ["ROUTE-0442"], ["App\\Http\\Controllers\\Emar\\EmarController@receiveStock"]),
    "VIS-003299": ("CAP-MED-CLIENT-DOSE-ADMINISTRATION", "component_callback_exact_route_action", "confirm-administration Dialog:1199; administrationForm.post:160-171", "resources/js/pages/clients/medical.tsx", "008a36479b8b179dd2fbabf16ed99adbb23204b216cd51acef57b2a04820fda4", ["ROUTE-0178"], ["App\\Http\\Controllers\\ClientMedicalController@storeAdministration"]),
    "VIS-003461": ("CAP-MED-CLIENT-DOSE-ADMINISTRATION", "component_callback_exact_route_action", "confirm-administration Dialog:2613; administrationForm.post:217-233", "resources/js/pages/operations/clients/medical.tsx", "2ffabc35640315308eca5aeb351f9432c96213c3e8d2c3f429e144bf3d987b4a", ["ROUTE-2021"], ["App\\Http\\Controllers\\ClientMedicalController@storeAdministration"]),
    "VIS-003300": ("CAP-MED-CD-DISCREPANCY-CLOSURE", "component_callback_exact_route_action", "close-discrepancy Dialog:1664; closeDiscForm.post:1686-1695", "resources/js/pages/clients/medical.tsx", "008a36479b8b179dd2fbabf16ed99adbb23204b216cd51acef57b2a04820fda4", ["ROUTE-0171"], ["App\\Http\\Controllers\\ClientMedicalController@closeControlledDiscrepancy"]),
}

WAVE8_ANCHOR_SOURCE_HASHES = {
    "resources/js/components/clients/profile/dialog-host.tsx": "cbd726634d4d92a17bd3f84cbc11200fa7d66f14c7438547a57472f477af3840",
    "resources/js/pages/emar/Handovers.tsx": "478c73d86a49b9ba0b494dce80da1bbd904136aeae5b6e5ad8a243e6de31ac8c",
    "resources/js/pages/Governance/Meetings/Show.tsx": "642797645cce174db4520e8908e208ab09c23f652ec0794c5cb01dc69a7f9c46",
    "resources/js/pages/sites/vendors-credentials/global.tsx": "8a31b7a3fbf67ccf0d82b0cb3b818ba615dff981b25fa0e493139adf3625fbfe",
}

# Independently reviewed Wave-9 hero-action ownership. Values are target,
# exact route IDs, anchor source, anchor SHA, bounded source scope, and optional
# distinct owner source/SHA. Shared page/family route envelopes are excluded.
WAVE9_HERO_ACTION_PROOFS = {
    "VIS-002516": ("CAP-SITE-PRODUCT-LIFECYCLE", ["ROUTE-0100"], "resources/js/pages/catering/products/index.tsx", "d0367d47eaf992a7b48760e3d7f6b5da1120edf3f1e0e8634e1853710743ca96", "PageHero New product -> openNew -> _isNew branch -> form.post('/catering/products') [anchor 116:21; owner 59-66,87-96,125-131]"),
    "VIS-002518": ("CAP-SITE-RECIPE-LIFECYCLE", ["ROUTE-0109"], "resources/js/pages/catering/recipes/index.tsx", "79c214e8caac17b7f73f89b5c54e8917c40e37c4c8ca79371364820230cce599", "PageHero New recipe Link('/catering/recipes/create') [anchor 41:21; owner 50-56]"),
    "VIS-002519": ("CAP-SITE-RECIPE-LIFECYCLE", ["ROUTE-0108"], "resources/js/pages/catering/recipes/show.tsx", "d2ac68746ef7abd2e4825d8a44c95b3a13daf8eef872ba786e45f7ed7a42982c", "PageHero Edit recipe Link(`/catering/recipes/${recipe.id}/edit`) [anchor 40:21; owner 47-54]"),
    "VIS-002520": ("CAP-SITE-DIETARY-TAG-LIFECYCLE", ["ROUTE-0111"], "resources/js/pages/catering/tags/index.tsx", "64c17ebf6e9d01351f7614e74e3d4b8c0520ecad8e2cc87a1c60d20a02cc455d", "PageHero New tag -> openNew -> _isNew branch -> form.post('/catering/tags') [anchor 80:21; owner 39-43,57-64,89-95]"),
    "VIS-002528": ("CAP-CR-BROADCAST-COMPOSE-SEND", ["ROUTE-0249"], "resources/js/pages/control-room/broadcast.tsx", "d2d6cc465f182cdd4e7d9f81c6e94e08a81ed4600ee65f5b546f5d1ccffbe3d3", "PageHero New broadcast -> setComposerOpen(true) -> mounted BroadcastWizard -> router.post('/control-room/broadcast') [anchor 116:17; page 125-137,280-283; wizard 68-307]", "resources/js/components/control-room/broadcast-wizard.tsx", "31e2c40a8d364f705f7f7970c5672023102695e331a13c83d16d41d2382dbc52"),
    "VIS-002532": ("CAP-CR-SIGNAL-TO-INCIDENT", ["ROUTE-0267"], "resources/js/pages/control-room/incidents.tsx", "a97885cdb2db31d97da8b26664cb776379edb4c88a527ee411cc6aa438681abb", "PageHero Flag incident -> setFlagOpen(true) -> local submitCreateAlert -> router.post('/control-room/incidents/flag') [anchor 241:17; owner 199-230,251-263,712-766]"),
    "VIS-002571": ("CAP-FIN-AUDIT-EXPORT-PACKAGE", ["ROUTE-0471"], "resources/js/pages/finance/audit-exports/Index.tsx", "eaebdb846f9b2640a8c6d6eb309fefe01a33cd9950cc5f7e736090782da30d7d", "PageHero New Export -> setCreateOpen(true) -> mounted AuditExportDialog -> form.post('/finance/audit-exports') [anchor 109:21; page 119-126,243; dialog 51-234]", "resources/js/components/finance/audit-export-dialog.tsx", "99892f7113a2e132364d1ec83f3d0e2a429a32449f6ea2afc6fd674167af9e11"),
    "VIS-002613": ("CAP-FIN-ACCOUNTING-INTEGRATION-CONNECTION-LIFECYCLE", ["ROUTE-0587"], "resources/js/pages/finance/Integrations/Index.tsx", "d3fbac326a499870dda71af3f7eda41fa8f2d42daf12d3443ebc2b7399891e21", "PageHero actions=<CreateIntegrationDialog> -> CreateIntegrationDialog form.post('/finance/integrations') [anchor 399:21; owner 111-199,408]"),
    "VIS-002662": ("CAP-GOV-AUDIT-LOG-EXPORT", ["ROUTE-0867"], "resources/js/pages/Governance/AuditLog/Index.tsx", "60037c6fc86d4163196a51364031a16f9d4118ff5f1e9fcc6cae5c832113e346", "PageHero Export CSV -> exportUrl download link [anchor 124:11; owner 102-108,133-139]"),
    "VIS-002669": ("CAP-GOV-CLINICAL-INDICATOR-OVERSIGHT", ["ROUTE-0900"], "resources/js/pages/Governance/Clinical/Dashboard.tsx", "cbd4ee2f7ab1ec9a3ffcce73a36027f5eff35dea8d4ad562a7812cd5fa68dde6", "PageHero Trends Link('/governance/clinical/trends') [anchor 89:21; owner 98-107]"),
    "VIS-002670": ("CAP-GOV-CLINICAL-INDICATOR-OVERSIGHT", ["ROUTE-0897"], "resources/js/pages/Governance/Clinical/Trends.tsx", "fd2847ed8f4e2cb75acb2f99578f65fd9398a156fd5dfb79cff81d9d6e47577c", "PageHero Dashboard Link('/governance/clinical') [anchor 93:21; owner 102-110]"),
    "VIS-002682": ("CAP-GOV-BOARD-EVALUATION-CAMPAIGN", ["ROUTE-0923", "ROUTE-0924"], "resources/js/pages/Governance/Evaluations/Show.tsx", "ee0190ff06e66f45bffc29f58b09a0d8a5f62732ed11bb4cafd80b322887fc56", "PageHero launch/close actions -> handleLaunch|handleClose -> router.post evaluation launch|close [anchor 67:11; owner 57-58,84-89]"),
    "VIS-002764": ("CAP-HR-PAYSLIP-ADMIN-DOWNLOAD", ["ROUTE-1591"], "resources/js/pages/hr/payroll/payslip-detail.tsx", "6d4cc2dcb94b8d7187d8e03edf14e7cc2a918f9667b92ccc09cfef8b3b0ee626", "PageHero Download Link(`/hr/payroll/payslips/${payslip.id}/download`) [anchor 123:21; owner 128-142]"),
    "VIS-002799": ("CAP-MED-AUDIT-RAW-CSV-EXPORT", ["ROUTE-1875"], "resources/js/pages/medications/audit.tsx", "985cf73c8ae5f33a6dc368c44efd3f0244000f01175828d61f592ac5426707d1", "PageHero Export CSV -> window.location.href medication audit export [anchor 153:21; owner 157-166]"),
    "VIS-002820": ("CAP-INC-INCIDENT-AUTHOR", ["ROUTE-1996"], "resources/js/pages/operations/clients/incidents.tsx", "cf3d24d41b0b86a3af5fefb276e317057ed4a986564f5c1938feca4d90cf7ddd", "PageHero New incident -> setShowNew -> local form.post operations-client incident [anchor 190:21; owner 201-213,219,428-436]"),
    "VIS-002957": ("CAP-SEC-DEVICE-IDENTITY-ATTRIBUTES", ["ROUTE-2560"], "resources/js/pages/security-devices/category.tsx", "8a5267d86e957b2ef3ef5b022c28358f36ac1cfecc67d5a43a93de8179b547c7", "PageHero Add device Link(`/security-devices/devices/create?domain=${pageConfig.domain}`) [anchor 127:17; owner 135-142]"),
    "VIS-002961": ("CAP-SEC-DEVICE-GROUP-LIFECYCLE", ["ROUTE-2533", "ROUTE-2538"], "resources/js/pages/security-devices/device-groups/show.tsx", "9aa2c4c93395dca346228466c36c821a7a452a0be5d76d9ffe479665219a889e", "PageHero Edit|Delete -> edit Link|deleteGroup -> router.delete device group [anchor 109:17; owner 93-96,120-131]"),
    "VIS-002973": ("CAP-PRIV-SYSTEM-AUDIT-LOG-EXPORT", ["ROUTE-2627"], "resources/js/pages/settings/audit-logs.tsx", "8de1c853de6939f6c15d5ca7cb707ef7e64abadbb60e547dce4962afd191faa0", "PageHero Export CSV -> exportHref download link [anchor 268:21; owner 277-284]"),
    "VIS-002981": ("CAP-SET-USER-ACCOUNT-LIFECYCLE", ["ROUTE-2964", "ROUTE-2968"], "resources/js/pages/settings/users/show.tsx", "f1b34ab6a57223c74a7bb7ffa3578417ee34a6a824b4aafd359d4c5811c2634b", "PageHero Approve|Suspend -> inline router.post user approve|suspend [anchor 265:17; owner 299-330]"),
    "VIS-003022": ("CAP-SET-USER-ACCOUNT-LIFECYCLE", ["ROUTE-2969"], "resources/js/pages/system/users/Index.tsx", "16e2b5c604b51a27effd1aa3c0bfcfcc9e8a248158b761f43f92f8dbd6592c57", "PageHero Create User Link('/system/users/create') [anchor 198:21; owner 208-215]"),
    "VIS-003054": ("CAP-FLEET-TRIP-LIFECYCLE", ["ROUTE-0846", "ROUTE-0849"], "resources/js/pages/fleet-assets/trips/playback.tsx", "22a5c979b676e5606feec60dbaa2b4132dc15e28bbe99f5f07d7daa81c68a1f1", "FleetCompactHero Close|Delete -> confirmation callbacks -> handleClose|handleDelete -> router.post|router.delete fleet trip [anchor 122:17; owner 81-92,150-175,324-333]"),
}

# Independently reviewed Wave-10 component-callback ownership. Each selected
# usage pins one local callback binding, one literal route, its sole target,
# and both component/controller source bytes. Broad page/family inheritance is
# deliberately excluded.
WAVE10_EXACT_COMPONENT_CALLBACK_PROOFS = {
    "VIS-003823": (
        "CAP-IT-PROVISIONING-REQUEST",
        "component_callback_exact_route_action",
        "ROUTE-1863",
        "it.provisioning.assign",
        "it/provisioning/{provisioning}/assign",
        "App\\Http\\Controllers\\It\\ItProvisioningController@assign",
        "resources/js/components/it/it-wizards.tsx:118:17",
        "endpoint_prop_post_to_AssignDialog_and_local_form_post_dispatch",
        "b731468f6aa7dd307c1eea6e8beefd573451038aafa255ac22dc57f15ec996d0",
        "c12041a0a2c3769b08a38efc5c48f10de54fad98c3248595678539b9ee031f14",
    ),
    "VIS-003824": (
        "CAP-IT-SUPPORT-TICKET",
        "component_callback_exact_route_action",
        "ROUTE-1867",
        "it.tickets.update",
        "it/tickets/{ticket}",
        "App\\Http\\Controllers\\It\\ItProvisioningController@updateTicket",
        "resources/js/components/it/it-wizards.tsx:129:17",
        "endpoint_prop_patch_to_AssignDialog_and_local_form_patch_dispatch",
        "b731468f6aa7dd307c1eea6e8beefd573451038aafa255ac22dc57f15ec996d0",
        "c12041a0a2c3769b08a38efc5c48f10de54fad98c3248595678539b9ee031f14",
    ),
}


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def load(path: Path) -> dict:
    with path.open("r", encoding="utf-8-sig") as handle:
        return json.load(handle)


matrix_input_sha = sha(MATRIX)
require(
    matrix_input_sha
    in {
        EXPECTED_MATRIX_SHA,
        EXPECTED_PRE_NORMALIZATION_MATRIX_SHA,
        EXPECTED_PRE_ENRICHMENT_RECONCILED_MATRIX_SHA,
        EXPECTED_RECONCILED_MATRIX_SHA,
        EXPECTED_PRE_GLOBAL_EXACT_MATRIX_SHA,
        EXPECTED_PRE_SPLIT_GLOBAL_MATRIX_SHA,
        EXPECTED_FINAL_902_MATRIX_SHA,
        EXPECTED_MATERIAL_ROUTE_WAVE_MATRIX_SHA,
        EXPECTED_FINAL_902_RELINKED_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE3_MATRIX_SHA,
        EXPECTED_POST_BVIS0011_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE4_MATRIX_SHA,
        EXPECTED_PRE_REQUIRED_VIEWPORT_RESTORE_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE5_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE6_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE7_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE8_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE9_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE10_MATRIX_SHA,
        EXPECTED_PAGE_REFRESH_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE11_MATRIX_SHA,
        EXPECTED_VISUAL_WAVE12_MATRIX_SHA,
    },
    "Visual matrix input SHA drift",
)
require(sha(MANIFEST) == EXPECTED_MANIFEST_SHA, "Manifest input SHA drift")
require(sha(INVENTORY) == EXPECTED_INVENTORY_SHA, "Inventory input SHA drift")
require(
    sha(RAW_VIEWPORT_EVIDENCE) == EXPECTED_RAW_VIEWPORT_EVIDENCE_SHA,
    "Raw rendered-component viewport evidence SHA drift",
)

manifest = load(MANIFEST)
inventory = load(INVENTORY)
require(manifest.get("audited_commit") == EXPECTED_COMMIT, "Manifest commit mismatch")
require(inventory.get("commit") == EXPECTED_COMMIT, "Inventory commit mismatch")

with MATRIX.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    input_fields = list(reader.fieldnames or [])
    input_rows = list(reader)

if "legacy_feature_id" in input_fields:
    generated_fields = {"feature_id", "working_feature_ids", "feature_link_status", "feature_link_evidence"}
    original_fields = []
    for field in input_fields:
        if field == "legacy_feature_id":
            original_fields.append("feature_id")
        elif field not in generated_fields:
            original_fields.append(field)
    original_rows = []
    for row in input_rows:
        original_rows.append({field: (row["legacy_feature_id"] if field == "feature_id" else row[field]) for field in original_fields})
else:
    original_fields = input_fields
    original_rows = input_rows

# BVIS-0011 temporarily replaced the audited System Users 1280x800 observation
# with a later 1280x720 remediation screenshot. Restore the required viewport
# from the pinned raw measurement and keep the later finding link explicitly
# separate. This changes evidence selection only, never runtime classification.
raw_viewport_evidence = load(RAW_VIEWPORT_EVIDENCE)
require(
    raw_viewport_evidence.get("auditedCommit") == EXPECTED_COMMIT,
    "Raw viewport evidence commit mismatch",
)
required_system_users_observations = [
    observation
    for observation in raw_viewport_evidence.get("observations", [])
    if observation.get("route_id") == "ROUTE-2969"
    and observation.get("component") == "system/users/Create"
    and observation.get("viewportLabel") == "1280x800"
]
require(
    len(required_system_users_observations) == 1,
    "Expected one raw System Users 1280x800 observation",
)
required_system_users_observation = required_system_users_observations[0]
required_system_users_rows = [
    row for row in original_rows if row.get("visual_id") == "VIS-001862"
]
require(len(required_system_users_rows) == 1, "Expected VIS-001862 exactly once")
required_system_users_row = required_system_users_rows[0]
required_system_users_row.update({
    "site_scope": "not visibly selected",
    "viewport": "1280x800",
    "state": "initial rendered state",
    "screenshot": "",
    "observed_notes": (
        "measured 1280x800; no document overflow; missing-name signal 0; "
        f"<44px heuristic {required_system_users_observation['smallControlCount']}; "
        "restored from pinned rendered-component-viewport-matrix.json; the later "
        "BVIS-0011 1280x720 remediation screenshot remains separate finding evidence "
        "[Capability link uncertainty: legacy family SET-USERS; 3 plausible canonical "
        "capabilities; retained primary CAP-SET-USERS-ACCOUNT-LIFECYCLE without "
        "claiming exclusivity.]"
    ),
    "finding_ids": "HR-STAFF-CREATION-PATH-01",
})

require(len(original_rows) == 8753, f"Expected 8753 visual rows, found {len(original_rows)}")
require(len({row["visual_id"] for row in original_rows}) == 8753, "Visual IDs are not unique")
require("feature_id" in original_fields, "Missing legacy feature_id column")

targets = manifest["targets"]
target_by_key = {row["working_key"]: row for row in targets}
require(len(target_by_key) == 902, "Manifest target key count mismatch")
manifest_targets_by_route: dict[str, set[str]] = defaultdict(set)
for target in targets:
    for route_id in target.get("route_ids", []):
        manifest_targets_by_route[str(route_id)].add(str(target["working_key"]))
family_to_targets: dict[str, set[str]] = defaultdict(set)
for target in targets:
    for family in target.get("source_family_ids", []):
        family_to_targets[str(family)].add(target["working_key"])

routes = inventory["routes"]
pages = inventory["pages"]
routes_by_pair: dict[tuple[str, str], list[dict]] = defaultdict(list)
routes_by_name: dict[str, list[dict]] = defaultdict(list)
routes_by_path: dict[str, list[dict]] = defaultdict(list)
routes_by_id: dict[str, dict] = {}
for route in routes:
    routes_by_pair[(str(route.get("name", "")), str(route.get("uri", "")))].append(route)
    routes_by_name[str(route.get("name", ""))].append(route)
    routes_by_path[str(route.get("uri", ""))].append(route)
    routes_by_id[str(route["route_id"])] = route

pages_by_key: dict[str, list[dict]] = defaultdict(list)
pages_by_file: dict[str, list[dict]] = defaultdict(list)
for page in pages:
    pages_by_key[str(page.get("page_key", ""))].append(page)
    pages_by_file[str(page.get("file", ""))].append(page)


def resolve_routes(row: dict[str, str]) -> list[dict]:
    name = row.get("route_name", "")
    path = row.get("route_path", "")
    if name and path:
        return routes_by_pair.get((name, path), [])
    if name:
        return routes_by_name.get(name, [])
    if path:
        return routes_by_path.get(path, [])
    return []


def resolve_pages(row: dict[str, str]) -> list[dict]:
    anchor = row.get("component_anchor", "")
    matches: dict[str, dict] = {}
    for page in pages_by_key.get(anchor, []):
        matches[page["page_id"]] = page
    for page in pages_by_file.get(anchor, []):
        matches[page["page_id"]] = page
    location = LOCATION_RE.match(anchor)
    if location:
        for page in pages_by_file.get(location.group(1), []):
            matches[page["page_id"]] = page
    return [matches[key] for key in sorted(matches)]


def target_candidates_for_routes(lineage: set[str], route_ids: set[str]) -> set[str]:
    return {
        key for key in lineage
        if set(map(str, target_by_key[key].get("route_ids", []))) & route_ids
    }


def target_candidates_for_pages(lineage: set[str], page_ids: set[str]) -> set[str]:
    return {
        key for key in lineage
        if set(map(str, target_by_key[key].get("page_ids", []))) & page_ids
    }


controller_source_cache: dict[Path, str] = {}
frontend_lines_cache: dict[Path, list[str]] = {}


def exact_route_proof(route: dict) -> tuple[str, str, str] | None:
    """Return target, action and exact controller file only for a sole proved route target."""
    route_id = str(route.get("route_id", ""))
    inventory_targets = {
        str(value) for value in route.get("working_canonical_feature_ids", [])
    }
    manifest_targets = manifest_targets_by_route.get(route_id, set())

    if (
        len(inventory_targets) != 1
        or len(manifest_targets) != 1
        or inventory_targets != manifest_targets
    ):
        return None

    action = str(route.get("action", ""))
    if "@" not in action:
        return None

    controller_class, controller_method = action.split("@", 1)
    if not controller_class.startswith("App\\") or not controller_method:
        return None

    controller_rel = "app/" + controller_class[4:].replace("\\", "/") + ".php"
    controller_path = REPO / controller_rel
    if not controller_path.is_file():
        return None

    if controller_path not in controller_source_cache:
        controller_source_cache[controller_path] = controller_path.read_text(
            encoding="utf-8", errors="replace"
        )

    if not re.search(
        rf"\bfunction\s+{re.escape(controller_method)}\s*\(",
        controller_source_cache[controller_path],
    ):
        return None

    return next(iter(inventory_targets)), action, controller_rel


def frontend_path_matches_uri(frontend_path: str, inventory_uri: str) -> bool:
    """Match a literal/template frontend URL to a current inventory URI."""
    frontend_path = frontend_path.lstrip("/").split("?", 1)[0]
    inventory_uri = inventory_uri.lstrip("/")

    pattern_parts: list[str] = []
    cursor = 0
    for match in TEMPLATE_EXPR_RE.finditer(frontend_path):
        pattern_parts.append(re.escape(frontend_path[cursor:match.start()]))
        pattern_parts.append(r"[^/]+")
        cursor = match.end()
    pattern_parts.append(re.escape(frontend_path[cursor:]))

    return re.fullmatch("".join(pattern_parts), inventory_uri) is not None


def component_scope_at_anchor(anchor: str) -> dict[str, object] | None:
    """Locate the top-level named dialog-like function owning the anchored JSX root."""
    location = OVERLAY_LOCATION_RE.match(anchor)
    if not location:
        return None

    relative_file = location.group(1)
    anchor_line = int(location.group(2))
    source_path = REPO / relative_file
    if not source_path.is_file():
        return None

    if source_path not in frontend_lines_cache:
        frontend_lines_cache[source_path] = source_path.read_text(
            encoding="utf-8", errors="replace"
        ).splitlines()

    lines = frontend_lines_cache[source_path]
    if not 1 <= anchor_line <= len(lines):
        return None

    declarations: list[tuple[int, str]] = []
    for line_number, text in enumerate(lines[:anchor_line], start=1):
        match = COMPONENT_DECL_RE.match(text)
        if match:
            declarations.append((line_number, match.group(1)))

    for start_line, component_name in reversed(declarations):
        end_line = next(
            (
                line_number
                for line_number in range(start_line + 1, len(lines) + 1)
                if lines[line_number - 1] == "}"
            ),
            None,
        )
        if end_line is not None and start_line <= anchor_line <= end_line:
            return {
                "name": component_name,
                "start_line": start_line,
                "end_line": end_line,
                "source": "\n".join(lines[start_line - 1:end_line]),
                "anchor_line": lines[anchor_line - 1],
            }

    return None


def component_overlay_proof(
    original: dict[str, str],
) -> tuple[str, list[dict], dict[str, object]] | None:
    if original.get("pattern_type") not in {
        "overlay/primitive-root",
        "overlay/custom-usage",
    }:
        return None

    scope = component_scope_at_anchor(original.get("component_anchor", ""))
    if scope is None:
        return None

    if f"<{original.get('implementation', '')}" not in str(scope["anchor_line"]):
        return None

    source = str(scope["source"])
    if DELEGATED_MUTATION_RE.search(source) or INDIRECT_MUTATION_RE.search(source):
        return None

    direct_calls = [
        (match.group(1).upper(), match.group(3))
        for match in DIRECT_MUTATION_RE.finditer(source)
    ]
    if not direct_calls:
        return None

    proved_routes: list[dict] = []
    target_values: list[str] = []
    for method, frontend_path in direct_calls:
        matches = [
            route
            for route in routes
            if str(route.get("method", "")).upper() == method
            and frontend_path_matches_uri(frontend_path, str(route.get("uri", "")))
        ]
        if len(matches) != 1:
            return None

        route = matches[0]
        proof = exact_route_proof(route)
        if proof is None:
            return None

        target, _, _ = proof
        target_values.append(target)
        proved_routes.append(route)

    if len(set(target_values)) != 1:
        return None

    unique_routes: list[dict] = []
    seen_route_ids: set[str] = set()
    for route in proved_routes:
        route_id = str(route["route_id"])
        if route_id not in seen_route_ids:
            seen_route_ids.add(route_id)
            unique_routes.append(route)

    return target_values[0], unique_routes, scope


new_fields: list[str] = []
for field in original_fields:
    if field == "feature_id":
        new_fields.extend([
            "legacy_feature_id",
            "feature_id",
            "working_feature_ids",
            "feature_link_status",
            "feature_link_evidence",
        ])
    else:
        new_fields.append(field)

result_rows: list[dict[str, str]] = []
status_counts: Counter[str] = Counter()
wave3_promotions: list[dict[str, str]] = []
wave4_promotions: list[dict[str, str]] = []
wave5_promotions: list[dict[str, str]] = []
wave6_promotions: list[dict[str, str]] = []
wave7_promotions: list[dict[str, str]] = []
wave8_promotions: list[dict[str, str]] = []
wave9_promotions: list[dict[str, str]] = []
wave10_promotions: list[dict[str, str]] = []
wave11_promotions: list[dict[str, object]] = []
wave12_promotions: list[dict[str, str]] = []

for original in original_rows:
    legacy = original["feature_id"]
    final_id = ""
    candidate_ids: list[str] = []
    status = ""
    evidence = ""

    if legacy in target_by_key:
        final_id = legacy
        candidate_ids = [legacy]
        status = "exact_working_key"
        evidence = f"legacy feature_id exactly equals final working key {legacy}"
    else:
        lineage = set(family_to_targets.get(legacy, set()))
        if len(lineage) == 1:
            final_id = next(iter(lineage))
            candidate_ids = [final_id]
            status = "source_family_one_to_one"
            evidence = f"source family {legacy} has exactly one final target"
        elif len(lineage) > 1:
            resolved_routes = resolve_routes(original)
            resolved_pages = resolve_pages(original)
            route_ids = {str(row["route_id"]) for row in resolved_routes}
            page_ids = {str(row["page_id"]) for row in resolved_pages}
            route_targets = target_candidates_for_routes(lineage, route_ids)
            page_targets = target_candidates_for_pages(lineage, page_ids)

            if route_targets and page_targets:
                intersection = route_targets & page_targets
                if len(intersection) == 1:
                    final_id = next(iter(intersection))
                    candidate_ids = [final_id]
                    status = "split_family_exact_route_page"
                elif not intersection:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_family_route_page_conflict"
                else:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_family_page_ambiguous"
            elif route_targets:
                if len(route_targets) == 1:
                    final_id = next(iter(route_targets))
                    candidate_ids = [final_id]
                    status = "split_family_exact_route"
                else:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_source_family"
            elif page_targets:
                if len(page_targets) == 1:
                    final_id = next(iter(page_targets))
                    candidate_ids = [final_id]
                    status = "split_family_exact_page"
                else:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_family_page_ambiguous"
            else:
                candidate_ids = sorted(lineage)
                status = "unresolved_split_source_family"

            evidence = (
                f"split source family {legacy}; inventory route_ids={'|'.join(sorted(route_ids)) or 'none'}; "
                f"route targets={'|'.join(sorted(route_targets)) or 'none'}; "
                f"inventory page_ids={'|'.join(sorted(page_ids)) or 'none'}; "
                f"page targets={'|'.join(sorted(page_targets)) or 'none'}; shared family envelope not promoted"
            )

            # Some retained family labels are stale even though the row's exact
            # inventory route/page relation points uniquely to a current target
            # outside that family envelope. Permit only the unique global exact
            # relation; shared relations and conflicts remain unresolved.
            if status.startswith("unresolved_"):
                all_targets = set(target_by_key)
                global_route_targets = target_candidates_for_routes(all_targets, route_ids)
                global_page_targets = target_candidates_for_pages(all_targets, page_ids)
                if (
                    global_route_targets
                    and global_page_targets
                    and len(global_route_targets & global_page_targets) == 1
                ):
                    final_id = next(iter(global_route_targets & global_page_targets))
                    status = "split_family_global_exact_route_page"
                elif global_route_targets and not global_page_targets and len(global_route_targets) == 1:
                    final_id = next(iter(global_route_targets))
                    status = "split_family_global_exact_route"
                elif global_page_targets and not global_route_targets and len(global_page_targets) == 1:
                    final_id = next(iter(global_page_targets))
                    status = "split_family_global_exact_page"

                if final_id:
                    candidate_ids = [final_id]
                    evidence += (
                        f"; global route targets={'|'.join(sorted(global_route_targets)) or 'none'}; "
                        f"global page targets={'|'.join(sorted(global_page_targets)) or 'none'}; "
                        "unique exact current target relation promoted outside the stale family envelope"
                    )
        else:
            # A legacy label may be absent from the retained source-family
            # register even when this exact visual row names an inventory route
            # and/or resolver page that has a unique current target relation.
            # Promote only that unique exact relation. A shared relation or a
            # route/page conflict remains unresolved; no family inference is
            # introduced here.
            resolved_routes = resolve_routes(original)
            resolved_pages = resolve_pages(original)
            route_ids = {str(row["route_id"]) for row in resolved_routes}
            page_ids = {str(row["page_id"]) for row in resolved_pages}
            all_targets = set(target_by_key)
            route_targets = target_candidates_for_routes(all_targets, route_ids)
            page_targets = target_candidates_for_pages(all_targets, page_ids)

            if original["visual_id"] in PAGE_REFRESH_PRESERVE_EXACT_PROOF_IDS:
                status = "unresolved_no_manifest_lineage"
            elif route_targets and page_targets and len(route_targets & page_targets) == 1:
                final_id = next(iter(route_targets & page_targets))
                status = "global_exact_route_page"
            elif route_targets and not page_targets and len(route_targets) == 1:
                final_id = next(iter(route_targets))
                status = "global_exact_route"
            elif page_targets and not route_targets and len(page_targets) == 1:
                final_id = next(iter(page_targets))
                status = "global_exact_page"
            else:
                status = "unresolved_no_manifest_lineage"

            if final_id:
                candidate_ids = [final_id]
                evidence = (
                    f"legacy feature_id {legacy} has no exact final key or retained source-family lineage; "
                    f"global exact inventory route_ids={'|'.join(sorted(route_ids)) or 'none'}; "
                    f"route targets={'|'.join(sorted(route_targets)) or 'none'}; "
                    f"inventory page_ids={'|'.join(sorted(page_ids)) or 'none'}; "
                    f"page targets={'|'.join(sorted(page_targets)) or 'none'}; "
                    "unique exact target relation promoted without family inheritance"
                )
            else:
                evidence = f"legacy feature_id {legacy} has no exact final key or retained source-family lineage"

    # A combined material-state envelope may still have one exact owner when
    # the intersection of every route's current manifest target set is a
    # singleton. This is stricter than union/family matching and cannot inherit
    # a target from only some of the actions in the row.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE11_ALL_ROUTE_INTERSECTION_PROOFS:
        proof = WAVE11_ALL_ROUTE_INTERSECTION_PROOFS[original["visual_id"]]
        target = str(proof["target"])
        expected_candidates = list(proof["prior_candidates"])
        expected_route_ids = list(proof["route_ids"])
        expected_route_names = list(proof["route_names"])
        expected_route_paths = list(proof["route_paths"])
        expected_anchor = str(proof["component_anchor"])
        expected_controller_sha = str(proof["controller_sha256"])

        require(status == "unresolved_split_source_family", f"Wave-11 prior status drift: {original['visual_id']}")
        require(candidate_ids == expected_candidates, f"Wave-11 candidate drift: {original['visual_id']}")
        require(original.get("classification", "") == "Not safely reproducible", f"Wave-11 classification drift: {original['visual_id']}")
        require(original.get("pattern_type", "") == "material-state-applicability", f"Wave-11 pattern drift: {original['visual_id']}")
        require(original.get("implementation", "") == "exact source-applicability map", f"Wave-11 implementation drift: {original['visual_id']}")
        require(original.get("component_anchor", "") == expected_anchor, f"Wave-11 anchor drift: {original['visual_id']}")
        require(
            [value.strip() for value in original.get("route_name", "").split("|")] == expected_route_names,
            f"Wave-11 route-name envelope drift: {original['visual_id']}",
        )
        require(
            [value.strip() for value in original.get("route_path", "").split("|")] == expected_route_paths,
            f"Wave-11 route-path envelope drift: {original['visual_id']}",
        )
        require(target in target_by_key, f"Wave-11 target missing: {original['visual_id']}")

        route_target_sets: list[set[str]] = []
        route_actions: list[str] = []
        for route_id, route_name, route_path in zip(
            expected_route_ids, expected_route_names, expected_route_paths
        ):
            require(route_id in routes_by_id, f"Wave-11 route missing: {original['visual_id']} {route_id}")
            route = routes_by_id[route_id]
            require(str(route.get("name", "")) == route_name, f"Wave-11 route-name drift: {route_id}")
            require(str(route.get("uri", "")) == route_path, f"Wave-11 route-path drift: {route_id}")
            inventory_targets = {
                str(value) for value in route.get("working_canonical_feature_ids", [])
            }
            manifest_targets = set(manifest_targets_by_route.get(route_id, set()))
            require(inventory_targets == manifest_targets, f"Wave-11 inventory/manifest target drift: {route_id}")
            require(target in manifest_targets, f"Wave-11 target absent from route: {route_id}")
            route_target_sets.append(manifest_targets)
            route_actions.append(str(route.get("action", "")))

        all_route_intersection = set.intersection(*route_target_sets)
        require(all_route_intersection == {target}, f"Wave-11 all-route intersection drift: {all_route_intersection}")
        require(
            set(expected_route_ids) <= set(map(str, target_by_key[target].get("route_ids", []))),
            f"Wave-11 manifest route set drift: {original['visual_id']}",
        )

        controller_path = REPO / expected_anchor
        require(controller_path.is_file(), f"Wave-11 controller missing: {original['visual_id']}")
        require(sha(controller_path) == expected_controller_sha, f"Wave-11 controller SHA drift: {original['visual_id']}")
        controller_source = controller_path.read_text(encoding="utf-8", errors="replace")
        for action in route_actions:
            require(action.startswith("App\\Http\\Controllers\\CalendarController@"), f"Wave-11 controller owner drift: {action}")
            method = action.rsplit("@", 1)[1]
            require(bool(re.search(rf"\bfunction\s+{re.escape(method)}\s*\(", controller_source)), f"Wave-11 controller method missing: {method}")

        final_id = target
        candidate_ids = [target]
        status = "material_state_all_routes_exact_intersection"
        evidence = (
            f"Wave-11 exact all-route intersection: routes {'|'.join(expected_route_ids)} have current "
            f"manifest target sets whose sole intersection is {target}; exact names, paths and controller "
            f"actions {'|'.join(route_actions)} are pinned to {expected_anchor} SHA-256 "
            f"{expected_controller_sha}. Final-ID lineage only; classification unchanged and no "
            "runtime/browser or material-state completion credit."
        )
        wave11_promotions.append({
            "visual_id": original["visual_id"],
            "target": target,
            "proposed_status": status,
            "prior_status": "unresolved_split_source_family",
            "prior_candidates": "|".join(expected_candidates),
            "route_ids": "|".join(expected_route_ids),
            "route_names": "|".join(expected_route_names),
            "route_paths": "|".join(expected_route_paths),
            "route_actions": "|".join(route_actions),
            "controller_source": expected_anchor,
            "controller_sha256": expected_controller_sha,
        })

    # A safe-route observation can carry a stale or mismatched page anchor. In
    # Wave-12 the route itself is exact and uniquely owned; the conflicting page
    # is validated and rejected rather than intersected or inherited.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE12_EXACT_ROUTE_REJECTED_PAGE_PROOFS:
        proof = WAVE12_EXACT_ROUTE_REJECTED_PAGE_PROOFS[original["visual_id"]]
        target = str(proof["target"])
        expected_candidates = list(proof["prior_candidates"])
        route_id = str(proof["route_id"])
        route_name = str(proof["route_name"])
        route_path = str(proof["route_path"])
        route_action = str(proof["route_action"])
        rejected_page_id = str(proof["rejected_page_id"])
        rejected_page_target = str(proof["rejected_page_target"])
        rejected_anchor = str(proof["rejected_component_anchor"])
        controller_source_path = str(proof["controller_source"])
        controller_sha = str(proof["controller_sha256"])

        require(status == "unresolved_split_source_family", f"Wave-12 prior status drift: {original['visual_id']}")
        require(candidate_ids == expected_candidates, f"Wave-12 candidate drift: {original['visual_id']}")
        require(original.get("classification", "") == "Observed", f"Wave-12 classification drift: {original['visual_id']}")
        require(original.get("pattern_type", "") == "safe-route-template", f"Wave-12 pattern drift: {original['visual_id']}")
        require(original.get("implementation", "") == "runtime route to rendered Inertia page", f"Wave-12 implementation drift: {original['visual_id']}")
        require(original.get("route_name", "") == route_name, f"Wave-12 route-name drift: {original['visual_id']}")
        require(original.get("route_path", "") == route_path, f"Wave-12 route-path drift: {original['visual_id']}")
        require(original.get("component_anchor", "") == rejected_anchor, f"Wave-12 rejected anchor drift: {original['visual_id']}")

        route = routes_by_id.get(route_id)
        require(route is not None, f"Wave-12 route missing: {route_id}")
        require(str(route.get("name", "")) == route_name, f"Wave-12 inventory route-name drift: {route_id}")
        require(str(route.get("uri", "")) == route_path, f"Wave-12 inventory route-path drift: {route_id}")
        require(str(route.get("action", "")) == route_action, f"Wave-12 route action drift: {route_id}")
        inventory_route_targets = {
            str(value) for value in route.get("working_canonical_feature_ids", [])
        }
        manifest_route_targets = set(manifest_targets_by_route.get(route_id, set()))
        require(inventory_route_targets == manifest_route_targets == {target}, f"Wave-12 route target drift: {route_id}")

        resolved_page_ids = {str(page["page_id"]) for page in resolve_pages(original)}
        require(resolved_page_ids == {rejected_page_id}, f"Wave-12 rejected page resolution drift: {original['visual_id']}")
        page_manifest_targets = {
            key for key, candidate in target_by_key.items()
            if rejected_page_id in set(map(str, candidate.get("page_ids", [])))
        }
        require(page_manifest_targets == {rejected_page_target}, f"Wave-12 rejected page target drift: {rejected_page_id}")
        require(target not in page_manifest_targets, f"Wave-12 rejected page unexpectedly owns target: {rejected_page_id}")

        controller_path = REPO / controller_source_path
        require(controller_path.is_file(), f"Wave-12 controller missing: {controller_source_path}")
        require(sha(controller_path) == controller_sha, f"Wave-12 controller SHA drift: {controller_source_path}")
        controller_source = controller_path.read_text(encoding="utf-8", errors="replace")
        require(bool(re.search(r"\bfunction\s+events\s*\(", controller_source)), "Wave-12 controller method missing: events")

        final_id = target
        candidate_ids = [target]
        status = "safe_route_exact_route_owner_page_anchor_rejected"
        evidence = (
            f"Wave-12 exact route owner: {route_id} ({route_name} {route_path}) has sole current target "
            f"{target} and controller action {route_action}; controller SHA-256 {controller_sha}. Recorded page "
            f"anchor {rejected_anchor} resolves {rejected_page_id} only to {rejected_page_target}, conflicts "
            "with the route, and is explicitly rejected as ownership evidence. Final-ID lineage only; the "
            "existing classification is preserved and no browser/runtime or completion credit is added."
        )
        wave12_promotions.append({
            "visual_id": original["visual_id"],
            "target": target,
            "proposed_status": status,
            "prior_status": "unresolved_split_source_family",
            "prior_candidates": "|".join(expected_candidates),
            "route_id": route_id,
            "route_name": route_name,
            "route_path": route_path,
            "route_action": route_action,
            "rejected_page_id": rejected_page_id,
            "rejected_page_target": rejected_page_target,
            "rejected_component_anchor": rejected_anchor,
            "controller_source": controller_source_path,
            "controller_sha256": controller_sha,
        })

    # A material-state row is route/action-specific, not a shared page envelope.
    # Promote only when the exact current route pair, inventory projection,
    # manifest route relation, frontend source and backend method all agree on
    # one target. This does not change its Not safely reproducible classification.
    if (
        status.startswith("unresolved_")
        and original.get("pattern_type") == "material-state-applicability"
        and original.get("implementation") == "exact source-applicability map"
        and original.get("route_name")
        and original.get("route_path")
    ):
        wave4_prior_status = status
        exact_routes = routes_by_pair.get(
            (original["route_name"], original["route_path"]), []
        )
        if len(exact_routes) == 1:
            exact_route = exact_routes[0]
            route_id = str(exact_route["route_id"])
            inventory_targets = {
                str(x) for x in exact_route.get("working_canonical_feature_ids", [])
            }
            manifest_targets = {
                key for key, target in target_by_key.items()
                if route_id in set(map(str, target.get("route_ids", [])))
            }
            action = str(exact_route.get("action", ""))
            controller_method_proved = False
            if "@" in action:
                controller_class, controller_method = action.split("@", 1)
                if controller_class.startswith("App\\") and controller_method:
                    controller_rel = (
                        "app/" + controller_class[4:].replace("\\", "/") + ".php"
                    )
                    controller_path = REPO / controller_rel
                    if controller_path.is_file():
                        controller_source = controller_path.read_text(
                            encoding="utf-8", errors="replace"
                        )
                        controller_method_proved = bool(re.search(
                            rf"\bfunction\s+{re.escape(controller_method)}\s*\(",
                            controller_source,
                        ))
            component_source_proved = (REPO / original["component_anchor"]).is_file()
            if (
                len(inventory_targets) == 1
                and inventory_targets == manifest_targets
                and controller_method_proved
                and component_source_proved
            ):
                final_id = next(iter(inventory_targets))
                candidate_ids = [final_id]
                status = "material_state_exact_route_action"
                evidence = (
                    f"material-state applicability row; exact current inventory route pair "
                    f"{original['route_name']} {original['route_path']} resolves only {route_id}; "
                    f"inventory working target and manifest route relation both uniquely equal {final_id}; "
                    f"backend action {action} has a current controller method declaration; "
                    f"component source {original['component_anchor']} exists; route-specific state assigned "
                    "without source-family or shared-page inheritance"
                )

    # Combined route envelopes are positionally paired. Promotion requires
    # every pair to be exact, every route to have the same sole target, and
    # every action's exact controller file to equal the row's source anchor.
    if (
        status.startswith("unresolved_")
        and original.get("pattern_type") == "material-state-applicability"
        and original.get("implementation") == "exact source-applicability map"
        and (
            "|" in original.get("route_name", "")
            or "|" in original.get("route_path", "")
        )
    ):
        route_names = [
            value.strip()
            for value in original.get("route_name", "").split("|")
            if value.strip()
        ]
        route_paths = [
            value.strip()
            for value in original.get("route_path", "").split("|")
            if value.strip()
        ]

        combined_routes: list[dict] = []
        combined_targets: list[str] = []
        combined_actions: list[str] = []
        combined_proved = len(route_names) >= 2 and len(route_names) == len(route_paths)

        if combined_proved:
            for route_name, route_path in zip(route_names, route_paths):
                exact_matches = routes_by_pair.get((route_name, route_path), [])
                if len(exact_matches) != 1:
                    combined_proved = False
                    break

                exact_route = exact_matches[0]
                proof = exact_route_proof(exact_route)
                if proof is None:
                    combined_proved = False
                    break

                target, action, controller_rel = proof
                if controller_rel != original.get("component_anchor"):
                    combined_proved = False
                    break

                combined_routes.append(exact_route)
                combined_targets.append(target)
                combined_actions.append(action)

        if combined_proved and len(set(combined_targets)) == 1:
            final_id = combined_targets[0]
            candidate_ids = [final_id]
            status = "material_state_combined_route_actions_exact"

            combined_route_ids_text = "|".join(
                str(route["route_id"]) for route in combined_routes
            )
            combined_actions_text = "|".join(combined_actions)

            evidence = (
                f"material-state combined route envelope; positional route name/path pairs "
                f"resolve uniquely to {combined_route_ids_text}; every inventory working target and manifest "
                f"route relation uniquely equals {final_id}; every action {combined_actions_text} has a current "
                f"controller method declaration in exact controller anchor "
                f"{original['component_anchor']}; combined route-action state assigned without "
                "source-family or shared-page inheritance"
            )

            wave3_promotions.append({
                "visual_id": original["visual_id"],
                "target": final_id,
                "proposed_status": status,
                "route_name": "|".join(str(route.get("name", "")) for route in combined_routes),
                "route_path": "|".join(str(route.get("uri", "")) for route in combined_routes),
                "route_ids": combined_route_ids_text,
                "actions": combined_actions_text,
                "component_anchor": original["component_anchor"],
                "component_scope": f"controller:{original['component_anchor']}",
            })

    # A named route set can still prove one target when duplicate route methods
    # share a URI and positional pairing is therefore impossible. Every name,
    # URI, target, controller method and exact page/controller owner must agree.
    if (
        status.startswith("unresolved_")
        and original.get("pattern_type") == "material-state-applicability"
        and original.get("implementation") == "exact source-applicability map"
    ):
        named_routes = [
            value.strip() for value in original.get("route_name", "").split("|")
            if value.strip()
        ]
        named_paths = sorted({
            value.strip() for value in original.get("route_path", "").split("|")
            if value.strip()
        })
        proved_routes: list[dict] = []
        proofs: list[tuple[str, str, str]] = []
        named_proved = len(named_routes) >= 2
        if named_proved:
            for route_name in named_routes:
                matches = routes_by_name.get(route_name, [])
                if len(matches) != 1:
                    named_proved = False
                    break
                proof = exact_route_proof(matches[0])
                if proof is None:
                    named_proved = False
                    break
                proved_routes.append(matches[0])
                proofs.append(proof)
        if named_proved:
            named_proved = sorted({str(route.get("uri", "")) for route in proved_routes}) == named_paths
        if named_proved:
            targets = {proof[0] for proof in proofs}
            named_proved = len(targets) == 1
        if named_proved:
            target = proofs[0][0]
            anchor = original.get("component_anchor", "")
            ownership = ""
            if anchor.endswith(".php"):
                controller_rels = {proof[2] for proof in proofs}
                if controller_rels == {anchor}:
                    ownership = f"controller:{anchor}"
            else:
                owner_pages = [
                    page for page in pages_by_file.get(anchor, [])
                    if target in {str(value) for value in page.get("working_canonical_feature_ids", [])}
                ]
                if len(owner_pages) == 1 and (REPO / anchor).is_file():
                    ownership = f"page:{owner_pages[0]['page_id']}:{anchor}"
            named_proved = bool(ownership)
        if named_proved:
            final_id = target
            candidate_ids = [final_id]
            status = "material_state_named_route_set_exact_ownership"
            route_ids_text = "|".join(str(route["route_id"]) for route in proved_routes)
            actions_text = "|".join(proof[1] for proof in proofs)
            evidence = (
                f"material-state named-route set; route names resolve uniquely to {route_ids_text}; "
                f"resolved route URI set exactly equals row route-path set; every route's one inventory "
                f"working target equals its one manifest target and every route target equals {final_id}; "
                f"every backend action {actions_text} has a current controller method declaration; "
                f"exact owner {ownership}; assigned without source-family or shared-page inheritance"
            )
            wave4_promotions.append({
                "visual_id": original["visual_id"],
                "target": final_id,
                "proposed_status": status,
                "prior_status": wave4_prior_status,
                "legacy": legacy,
                "route_name": original.get("route_name", ""),
                "route_path": original.get("route_path", ""),
                "route_ids": route_ids_text,
                "actions": actions_text,
                "component_anchor": original.get("component_anchor", ""),
                "ownership": ownership,
                "classification": original.get("classification", ""),
            })

    # Custom-component callsites that remained unresolved after generic overlay
    # analysis are promoted only from the independently source-reviewed proof
    # register above. Revalidate the exact callsite, component source, current
    # routes, sole target relation and backend method before assigning.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE5_CUSTOM_COMPONENT_PROOFS:
        prior_status = status
        target, component, component_file, component_lines, route_ids = WAVE5_CUSTOM_COMPONENT_PROOFS[original["visual_id"]]
        require(original.get("pattern_type") == "overlay/custom-usage", f"Wave-5 pattern drift: {original['visual_id']}")
        callsite = LOCATION_RE.match(original.get("component_anchor", ""))
        require(callsite is not None, f"Wave-5 callsite missing: {original['visual_id']}")
        callsite_path = REPO / callsite.group(1)
        require(callsite_path.is_file(), f"Wave-5 callsite file missing: {original['visual_id']}")
        callsite_lines = callsite_path.read_text(encoding="utf-8", errors="replace").splitlines()
        callsite_line_number = int(callsite.group(2))
        require(1 <= callsite_line_number <= len(callsite_lines), f"Wave-5 callsite line drift: {original['visual_id']}")
        require(component in callsite_lines[callsite_line_number - 1], f"Wave-5 component binding drift: {original['visual_id']}")

        component_path = REPO / component_file
        require(component_path.is_file(), f"Wave-5 component file missing: {original['visual_id']}")
        component_source = component_path.read_text(encoding="utf-8", errors="replace")
        require(re.search(rf"\b{re.escape(component)}\b", component_source), f"Wave-5 component declaration missing: {original['visual_id']}")

        proved_routes = []
        proofs = []
        for route_id in route_ids:
            require(route_id in routes_by_id, f"Wave-5 route absent: {original['visual_id']} {route_id}")
            route = routes_by_id[route_id]
            proof = exact_route_proof(route)
            require(proof is not None and proof[0] == target, f"Wave-5 route/target mismatch: {original['visual_id']} {route_id}")
            proved_routes.append(route)
            proofs.append(proof)

        require(target in target_by_key, f"Wave-5 target absent: {target}")
        final_id = target
        candidate_ids = [target]
        status = "custom_component_exact_route_actions"
        route_ids_text = "|".join(route_ids)
        actions_text = "|".join(proof[1] for proof in proofs)
        evidence = (
            f"custom component usage; exact JSX callsite {original['component_anchor']} resolves uniquely "
            f"to named component {component} at {component_file}:{component_lines}; complete component "
            f"mutation set resolves to inventory route(s) {route_ids_text}; every inventory working target "
            f"and manifest route relation uniquely equals {target}; current backend action(s) {actions_text} "
            "have current controller method declarations; no indirect or delegated mutation transport occurs "
            "in the component scope; assigned without source-family or shared-page inheritance"
        )
        wave5_promotions.append({
            "visual_id": original["visual_id"],
            "target": target,
            "proposed_status": status,
            "prior_status": prior_status,
            "callsite": original["component_anchor"],
            "component": component,
            "component_file": component_file,
            "component_lines": component_lines,
            "component_file_sha256": sha(component_path),
            "route_ids": route_ids_text,
            "route_names": "|".join(str(route.get("name", "")) for route in proved_routes),
            "route_paths": "|".join(str(route.get("uri", "")) for route in proved_routes),
            "actions": actions_text,
            "classification": original.get("classification", ""),
        })

    # Exact named-component and callback ownership that remains ambiguous at
    # page/family level. Each proof is independently source reviewed and pinned
    # to exact source bytes, exact current route IDs and one manifest target.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE6_EXACT_COMPONENT_PROOFS:
        prior_status = status
        (
            target, proposed_status, expected_classification, prior_candidates,
            anchor, source, scope, source_sha, route_ids, binding_tokens,
        ) = WAVE6_EXACT_COMPONENT_PROOFS[original["visual_id"]]
        require(prior_status == "unresolved_split_family_page_ambiguous", f"Wave-6 prior-status drift: {original['visual_id']}")
        require("|".join(candidate_ids) == prior_candidates, f"Wave-6 candidate drift: {original['visual_id']}")
        require(original.get("component_anchor", "") == anchor, f"Wave-6 anchor drift: {original['visual_id']}")
        require(original.get("classification", "") == expected_classification, f"Wave-6 classification drift: {original['visual_id']}")
        require(original.get("pattern_type") == "overlay/custom-usage", f"Wave-6 pattern drift: {original['visual_id']}")

        source_path = REPO / source
        require(source_path.is_file(), f"Wave-6 source missing: {original['visual_id']}")
        source_text = source_path.read_text(encoding="utf-8", errors="replace")
        require(sha(source_path) == source_sha, f"Wave-6 source SHA drift: {original['visual_id']}")
        require(all(token in source_text for token in binding_tokens), f"Wave-6 binding token drift: {original['visual_id']}")

        anchor_match = LOCATION_RE.match(anchor)
        require(anchor_match is not None, f"Wave-6 callsite parse failed: {original['visual_id']}")
        anchor_path = REPO / anchor_match.group(1)
        require(anchor_path.is_file(), f"Wave-6 callsite file missing: {original['visual_id']}")
        anchor_lines = anchor_path.read_text(encoding="utf-8", errors="replace").splitlines()
        anchor_line = int(anchor_match.group(2))
        require(1 <= anchor_line <= len(anchor_lines), f"Wave-6 callsite line drift: {original['visual_id']}")
        if proposed_status == "custom_component_exact_route_actions":
            component_match = re.search(r"function\s+([A-Za-z_$][\w$]*)", scope)
            require(component_match is not None and component_match.group(1) in anchor_lines[anchor_line - 1], f"Wave-6 component callsite binding drift: {original['visual_id']}")

        proved_routes = []
        actions = []
        for route_id in route_ids:
            require(route_id in routes_by_id, f"Wave-6 route missing: {original['visual_id']} {route_id}")
            route = routes_by_id[route_id]
            proof = exact_route_proof(route)
            require(proof is not None and proof[0] == target, f"Wave-6 route target drift: {original['visual_id']} {route_id}")
            require(route_id in set(map(str, target_by_key[target].get("route_ids", []))), f"Wave-6 manifest route drift: {original['visual_id']} {route_id}")
            proved_routes.append(route)
            actions.append(proof[1])

        final_id = target
        candidate_ids = [target]
        status = proposed_status
        route_ids_text = "|".join(route_ids)
        actions_text = "|".join(actions)
        evidence = (
            f"exact component ownership; anchor {anchor} binds pinned source {source} scope {scope}; "
            f"exact route(s) {route_ids_text}; every inventory working target and manifest route relation "
            f"uniquely equals {target}; backend action(s) {actions_text} have current controller method "
            "declarations; assigned without source-family, shared-page, runtime or classification inheritance"
        )
        wave6_promotions.append({
            "visual_id": original["visual_id"], "prior_status": prior_status,
            "prior_candidates": prior_candidates, "target": target,
            "proposed_status": proposed_status, "proof_kind": "named_component" if proposed_status.startswith("custom_component") else "exact_callback",
            "anchor": anchor, "source": source, "scope": scope,
            "source_sha256": source_sha, "route_ids": route_ids_text,
            "route_names": "|".join(str(route.get("name", "")) for route in proved_routes),
            "route_paths": "|".join(str(route.get("uri", "")) for route in proved_routes),
            "actions": actions_text, "classification": expected_classification,
        })

    # Exact component/callback ownership wave. This repeats the source/route/
    # manifest/controller checks independently of page or source-family lineage.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE7_EXACT_COMPONENT_PROOFS:
        (
            target, proposed_status, expected_classification, expected_prior_status,
            expected_prior_candidates, anchor, source, scope, source_sha,
            route_ids, binding_tokens,
        ) = WAVE7_EXACT_COMPONENT_PROOFS[original["visual_id"]]
        prior_status = status
        prior_candidates = "|".join(candidate_ids)
        require(prior_status == expected_prior_status, f"Wave-7 prior-status drift: {original['visual_id']}")
        require(prior_candidates == expected_prior_candidates, f"Wave-7 candidate drift: {original['visual_id']}")
        require(original.get("component_anchor", "") == anchor, f"Wave-7 anchor drift: {original['visual_id']}")
        require(original.get("classification", "") == expected_classification, f"Wave-7 classification drift: {original['visual_id']}")

        source_path = REPO / source
        require(source_path.is_file(), f"Wave-7 source missing: {original['visual_id']}")
        source_text = source_path.read_text(encoding="utf-8", errors="replace")
        require(sha(source_path) == source_sha, f"Wave-7 source SHA drift: {original['visual_id']}")
        require(all(token in source_text for token in binding_tokens), f"Wave-7 binding drift: {original['visual_id']}")

        anchor_match = LOCATION_RE.match(anchor)
        require(anchor_match is not None and anchor_match.group(1) == source, f"Wave-7 callsite source drift: {original['visual_id']}")
        anchor_lines = source_text.splitlines()
        anchor_line = int(anchor_match.group(2))
        require(1 <= anchor_line <= len(anchor_lines), f"Wave-7 callsite line drift: {original['visual_id']}")

        proved_routes = []
        controller_loci = []
        controller_hashes = []
        actions = []
        for route_id in route_ids:
            require(route_id in routes_by_id, f"Wave-7 route missing: {original['visual_id']} {route_id}")
            route = routes_by_id[route_id]
            proof = exact_route_proof(route)
            require(proof is not None and proof[0] == target, f"Wave-7 route target drift: {original['visual_id']} {route_id}")
            require(route_id in set(map(str, target_by_key[target].get("route_ids", []))), f"Wave-7 manifest route drift: {original['visual_id']} {route_id}")
            proved_routes.append(route)
            actions.append(proof[1])
            controller_loci.append(proof[2])
            controller_hashes.append(sha(REPO / proof[2]))

        final_id = target
        candidate_ids = [target]
        status = proposed_status
        route_ids_text = "|".join(route_ids)
        actions_text = "|".join(actions)
        evidence = (
            f"exact visual ownership; anchor {anchor} binds pinned source {source} scope {scope}; "
            f"exact route(s) {route_ids_text}; every inventory working target and manifest route relation "
            f"uniquely equals {target}; backend action(s) {actions_text} have current controller method "
            "declarations; assigned without source-family, shared-page, runtime or classification inheritance"
        )
        wave7_promotions.append({
            "visual_id": original["visual_id"], "target": target,
            "proposed_status": proposed_status, "prior_status": prior_status,
            "prior_candidates": prior_candidates, "classification": expected_classification,
            "component_anchor": anchor, "visual_anchor_source": anchor_match.group(1),
            "visual_anchor_source_sha256": sha(REPO / anchor_match.group(1)),
            "proof_source": source, "source_sha256": source_sha,
            "source_scope": scope, "binding_loci": scope.split("; ", 2)[-1],
            "route_ids": route_ids_text,
            "route_names": "|".join(str(route.get("name", "")) for route in proved_routes),
            "route_paths": "|".join(str(route.get("uri", "")) for route in proved_routes),
            "actions": actions_text, "controller_loci": "|".join(controller_loci),
            "controller_hashes": "|".join(controller_hashes),
        })

    # Independently reviewed Wave-8 ownership proofs. Revalidate the exact
    # visual anchor, pinned source bytes, current inventory route/action,
    # manifest relation and controller method before assigning one target.
    # This changes final-ID lineage only; the original classification remains.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE8_EXACT_OVERLAY_PROOFS:
        (
            target, proposed_status, scope, proof_source, source_sha,
            expected_route_ids, expected_actions,
        ) = WAVE8_EXACT_OVERLAY_PROOFS[original["visual_id"]]
        prior_status = status
        prior_candidates = "|".join(candidate_ids)
        classification = original.get("classification", "")
        component_anchor = original.get("component_anchor", "")

        require(original.get("pattern_type", "").startswith("overlay/"), f"Wave-8 pattern drift: {original['visual_id']}")
        require(expected_route_ids == sorted(set(expected_route_ids)), f"Wave-8 route order drift: {original['visual_id']}")
        require(expected_actions == sorted(expected_actions), f"Wave-8 action order drift: {original['visual_id']}")
        proof_path = REPO / proof_source
        require(proof_path.is_file(), f"Wave-8 proof source missing: {original['visual_id']}")
        require(sha(proof_path) == source_sha, f"Wave-8 proof source SHA drift: {original['visual_id']}")

        anchor_match = LOCATION_RE.match(component_anchor)
        require(anchor_match is not None, f"Wave-8 visual anchor parse failed: {original['visual_id']}")
        anchor_source = anchor_match.group(1)
        anchor_path = REPO / anchor_source
        require(anchor_path.is_file(), f"Wave-8 visual anchor source missing: {original['visual_id']}")
        anchor_lines = anchor_path.read_text(encoding="utf-8", errors="replace").splitlines()
        anchor_line = int(anchor_match.group(2))
        require(1 <= anchor_line <= len(anchor_lines), f"Wave-8 visual anchor line drift: {original['visual_id']}")
        require(bool(anchor_lines[anchor_line - 1].strip()), f"Wave-8 visual anchor became blank: {original['visual_id']}")
        expected_anchor_sha = WAVE8_ANCHOR_SOURCE_HASHES.get(
            anchor_source,
            source_sha if anchor_source == proof_source else "",
        )
        require(bool(expected_anchor_sha), f"Wave-8 visual anchor source is not pinned: {original['visual_id']}")
        require(sha(anchor_path) == expected_anchor_sha, f"Wave-8 visual anchor source SHA drift: {original['visual_id']}")

        require(target in target_by_key, f"Wave-8 target missing: {original['visual_id']}")
        proved_routes = []
        proved_actions = []
        for route_id in sorted(expected_route_ids):
            require(route_id in routes_by_id, f"Wave-8 route missing: {original['visual_id']} {route_id}")
            route = routes_by_id[route_id]
            proof = exact_route_proof(route)
            require(proof is not None and proof[0] == target, f"Wave-8 route target drift: {original['visual_id']} {route_id}")
            require(route_id in set(map(str, target_by_key[target].get("route_ids", []))), f"Wave-8 manifest route drift: {original['visual_id']} {route_id}")
            proved_routes.append(route)
            proved_actions.append(proof[1])

        route_ids_text = "|".join(sorted(str(route["route_id"]) for route in proved_routes))
        route_names_text = "|".join(sorted(str(route.get("name", "")) for route in proved_routes))
        route_paths_text = "|".join(sorted(str(route.get("uri", "")) for route in proved_routes))
        actions_text = "|".join(sorted(proved_actions))
        require(tuple(sorted(proved_actions)) == tuple(sorted(expected_actions)), f"Wave-8 action drift: {original['visual_id']}")

        final_id = target
        candidate_ids = [target]
        status = proposed_status
        evidence = (
            f"Wave-8 exact overlay ownership: {scope}; audited source SHA-256 {source_sha}; "
            f"exact route(s) {route_ids_text}; every selected route/action target equals {target}; "
            f"controller action(s) {actions_text}. Final-ID lineage only; classification unchanged."
        )
        wave8_promotions.append({
            "visual_id": original["visual_id"],
            "target": target,
            "proposed_status": proposed_status,
            "prior_status": prior_status,
            "prior_candidates": prior_candidates,
            "classification": classification,
            "component_anchor": component_anchor,
            "proof_source": proof_source,
            "source_sha256": source_sha,
            "scope": scope,
            "route_ids": route_ids_text,
            "route_names": route_names_text,
            "route_paths": route_paths_text,
            "actions": actions_text,
        })

    # Independently reviewed Wave-9 hero-action ownership. Only an exact
    # action/component owner with pinned source bytes and one target across
    # every selected current route may supply final-ID lineage.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE9_HERO_ACTION_PROOFS:
        wave9_proof = WAVE9_HERO_ACTION_PROOFS[original["visual_id"]]
        target = wave9_proof[0]
        expected_route_ids = wave9_proof[1]
        anchor_source = wave9_proof[2]
        anchor_sha = wave9_proof[3]
        scope = wave9_proof[4]
        owner_source = wave9_proof[5] if len(wave9_proof) == 7 else anchor_source
        owner_sha = wave9_proof[6] if len(wave9_proof) == 7 else anchor_sha
        proposed_status = "hero_component_exact_route_actions"
        prior_status = status
        classification = original.get("classification", "")
        component_anchor = original.get("component_anchor", "")

        require(original.get("pattern_type", "").startswith("hero/"), f"Wave-9 pattern drift: {original['visual_id']}")
        require(classification == "Source-inferred", f"Wave-9 classification drift: {original['visual_id']}")
        require(expected_route_ids == sorted(set(expected_route_ids)), f"Wave-9 route order drift: {original['visual_id']}")
        require(target in target_by_key, f"Wave-9 target missing: {original['visual_id']}")

        anchor_match = LOCATION_RE.match(component_anchor)
        require(anchor_match is not None, f"Wave-9 visual anchor parse failed: {original['visual_id']}")
        require(anchor_match.group(1) == anchor_source, f"Wave-9 anchor source drift: {original['visual_id']}")
        anchor_path = REPO / anchor_source
        require(anchor_path.is_file(), f"Wave-9 anchor source missing: {original['visual_id']}")
        require(sha(anchor_path) == anchor_sha, f"Wave-9 anchor SHA drift: {original['visual_id']}")
        anchor_lines = anchor_path.read_text(encoding="utf-8", errors="replace").splitlines()
        anchor_line = int(anchor_match.group(2))
        require(1 <= anchor_line <= len(anchor_lines), f"Wave-9 anchor line drift: {original['visual_id']}")
        require(bool(anchor_lines[anchor_line - 1].strip()), f"Wave-9 anchor became blank: {original['visual_id']}")

        owner_path = REPO / owner_source
        require(owner_path.is_file(), f"Wave-9 owner source missing: {original['visual_id']}")
        require(sha(owner_path) == owner_sha, f"Wave-9 owner SHA drift: {original['visual_id']}")

        proved_routes: list[dict] = []
        proved_actions: list[str] = []
        controller_proofs: list[str] = []
        for route_id in expected_route_ids:
            require(route_id in routes_by_id, f"Wave-9 route missing: {original['visual_id']} {route_id}")
            route = routes_by_id[route_id]
            proof = exact_route_proof(route)
            require(proof is not None and proof[0] == target, f"Wave-9 route target drift: {original['visual_id']} {route_id}")
            require(route_id in set(map(str, target_by_key[target].get("route_ids", []))), f"Wave-9 manifest route drift: {original['visual_id']} {route_id}")
            proved_routes.append(route)
            proved_actions.append(proof[1])
            controller_proofs.append("=".join((route_id, proof[1], sha(REPO / proof[2]))))

        route_ids_text = "|".join(sorted(str(route["route_id"]) for route in proved_routes))
        route_names_text = "|".join(sorted(str(route.get("name", "")) for route in proved_routes))
        route_paths_text = "|".join(sorted(str(route.get("uri", "")) for route in proved_routes))
        actions_text = "|".join(sorted(proved_actions))
        controller_proofs_text = "|".join(sorted(controller_proofs))

        final_id = target
        candidate_ids = [target]
        status = proposed_status
        evidence = (
            f"Wave-9 exact hero-action ownership: {scope}; audited anchor SHA-256 {anchor_sha}; "
            f"audited owner SHA-256 {owner_sha}; exact route(s) {route_ids_text}; every selected "
            f"route/action target equals {target}; controller action(s) {actions_text}. Final-ID "
            "lineage only; classification unchanged."
        )
        wave9_promotions.append({
            "visual_id": original["visual_id"],
            "target": target,
            "proposed_status": proposed_status,
            "prior_status": prior_status,
            "classification": classification,
            "component_anchor": component_anchor,
            "anchor_source": anchor_source,
            "anchor_sha256": anchor_sha,
            "owner_source": owner_source,
            "owner_sha256": owner_sha,
            "route_ids": route_ids_text,
            "route_names": route_names_text,
            "route_paths": route_paths_text,
            "actions": actions_text,
            "controller_proofs": controller_proofs_text,
            "scope": scope,
        })

    # Independently reviewed Wave-10 local component-callback ownership. The
    # two source-family candidates are narrowed only by one pinned callback,
    # literal route, unique target, and controller implementation.
    if status.startswith("unresolved_") and original["visual_id"] in WAVE10_EXACT_COMPONENT_CALLBACK_PROOFS:
        (
            target,
            proposed_status,
            route_id,
            expected_route_name,
            expected_route_path,
            expected_action,
            expected_anchor,
            binding,
            component_sha,
            controller_sha,
        ) = WAVE10_EXACT_COMPONENT_CALLBACK_PROOFS[original["visual_id"]]
        require(status == "unresolved_split_source_family", f"Wave-10 prior status drift: {original['visual_id']}")
        require(
            candidate_ids == ["CAP-IT-PROVISIONING-REQUEST", "CAP-IT-SUPPORT-TICKET"],
            f"Wave-10 candidate drift: {original['visual_id']}",
        )
        require(original.get("classification", "") == "Source-inferred", f"Wave-10 classification drift: {original['visual_id']}")
        require(original.get("component_anchor", "") == expected_anchor, f"Wave-10 anchor drift: {original['visual_id']}")
        require(original.get("pattern_type", "") == "overlay/custom-usage", f"Wave-10 pattern drift: {original['visual_id']}")
        require(target in target_by_key, f"Wave-10 target missing: {original['visual_id']}")
        require(route_id in routes_by_id, f"Wave-10 route missing: {original['visual_id']}")

        component_source = expected_anchor.split(":", 1)[0]
        component_path = REPO / component_source
        require(component_path.is_file(), f"Wave-10 component missing: {original['visual_id']}")
        require(sha(component_path) == component_sha, f"Wave-10 component SHA drift: {original['visual_id']}")
        anchor_match = OVERLAY_LOCATION_RE.match(expected_anchor)
        require(anchor_match is not None, f"Wave-10 anchor format drift: {original['visual_id']}")
        anchor_lines = component_path.read_text(encoding="utf-8", errors="replace").splitlines()
        anchor_line = int(anchor_match.group(2))
        require(1 <= anchor_line <= len(anchor_lines), f"Wave-10 anchor line drift: {original['visual_id']}")
        require(bool(anchor_lines[anchor_line - 1].strip()), f"Wave-10 anchor became blank: {original['visual_id']}")

        route = routes_by_id[route_id]
        proof = exact_route_proof(route)
        require(proof is not None and proof[0] == target, f"Wave-10 route target drift: {original['visual_id']}")
        require(route_id in set(map(str, target_by_key[target].get("route_ids", []))), f"Wave-10 manifest route drift: {original['visual_id']}")
        require(str(route.get("name", "")) == expected_route_name, f"Wave-10 route-name drift: {original['visual_id']}")
        require(str(route.get("uri", "")) == expected_route_path, f"Wave-10 route-path drift: {original['visual_id']}")
        require(proof[1] == expected_action, f"Wave-10 controller-action drift: {original['visual_id']}")
        controller_path = REPO / proof[2]
        require(controller_path.is_file(), f"Wave-10 controller missing: {original['visual_id']}")
        require(sha(controller_path) == controller_sha, f"Wave-10 controller SHA drift: {original['visual_id']}")

        final_id = target
        candidate_ids = [target]
        status = proposed_status
        evidence = (
            f"Wave-10 exact component-callback ownership: {binding}; audited component SHA-256 {component_sha}; "
            f"exact route {route_id} ({expected_route_name} {expected_route_path}); every selected route/action "
            f"target equals {target}; controller action {expected_action}; audited controller SHA-256 "
            f"{controller_sha}. Final-ID lineage only; classification unchanged."
        )
        wave10_promotions.append({
            "visual_id": original["visual_id"],
            "target": target,
            "proposed_status": proposed_status,
            "route_id": route_id,
            "route_name": expected_route_name,
            "route_path": expected_route_path,
            "controller_action": expected_action,
            "component_anchor": expected_anchor,
            "binding": binding,
            "component_sha256": component_sha,
            "controller_sha256": controller_sha,
        })

    # Overlay promotion is based on its owning named component's complete set
    # of direct literal mutations, never the surrounding page envelope.
    if status.startswith("unresolved_"):
        overlay_proof = component_overlay_proof(original)
        if overlay_proof is not None:
            final_id, overlay_routes, component_scope = overlay_proof
            candidate_ids = [final_id]
            status = "component_overlay_exact_route_actions"

            overlay_route_ids_text = "|".join(
                str(route["route_id"]) for route in overlay_routes
            )
            overlay_actions_text = "|".join(
                str(route.get("action", "")) for route in overlay_routes
            )
            component_name = str(component_scope["name"])
            component_lines = (
                f"{component_scope['start_line']}-{component_scope['end_line']}"
            )

            evidence = (
                f"component-owned overlay; anchor {original['component_anchor']} is inside named "
                f"component {component_name} at source lines {component_lines}; direct literal "
                f"mutation route(s) {overlay_route_ids_text}; every inventory working target and manifest "
                f"route relation uniquely equals {final_id}; every backend action {overlay_actions_text} has "
                "a current controller method declaration; assigned without source-family or "
                "shared-page inheritance"
            )

            wave3_promotions.append({
                "visual_id": original["visual_id"],
                "target": final_id,
                "proposed_status": status,
                "route_name": "|".join(str(route.get("name", "")) for route in overlay_routes),
                "route_path": "|".join(str(route.get("uri", "")) for route in overlay_routes),
                "route_ids": overlay_route_ids_text,
                "actions": overlay_actions_text,
                "component_anchor": original["component_anchor"],
                "component_scope": f"function:{component_name}:{component_lines}",
            })

    require(bool(final_id) == (not status.startswith("unresolved_")), f"Resolved/status mismatch for {original['visual_id']}")
    if final_id:
        require(final_id in target_by_key and candidate_ids == [final_id], f"Invalid final assignment for {original['visual_id']}")
    else:
        require(not final_id, f"Unresolved row has final ID: {original['visual_id']}")
    require(candidate_ids == sorted(set(candidate_ids)), f"Candidate IDs not sorted/unique: {original['visual_id']}")
    require(set(candidate_ids) <= set(target_by_key), f"Unknown candidate: {original['visual_id']}")

    row: dict[str, str] = {}
    for field in original_fields:
        if field == "feature_id":
            row["legacy_feature_id"] = legacy
            row["feature_id"] = final_id
            row["working_feature_ids"] = "|".join(candidate_ids)
            row["feature_link_status"] = status
            row["feature_link_evidence"] = evidence
        else:
            # The governing prompt permits only Observed, Source-inferred,
            # Not safely reproducible, or Blocked for browser claims. Preserve
            # the underlying notes while normalizing two historical labels:
            # four Observed-light rows were browser-measured, while five
            # Dead/unreachable rows are static source conclusions rather than
            # browser observations.
            row[field] = (
                {"Observed-light": "Observed", "Dead/unreachable": "Source-inferred"}.get(
                    original[field], original[field]
                )
                if field == "classification"
                else original[field]
            )
    result_rows.append(row)
    status_counts[status] += 1

expected_status = Counter({
    "exact_working_key": 3497,
    "source_family_one_to_one": 1546,
    "split_family_exact_route": 61,
    "split_family_exact_page": 770,
    "split_family_exact_route_page": 1086,
    "global_exact_route_page": 353,
    "global_exact_route": 109,
    "global_exact_page": 227,
    "split_family_global_exact_route_page": 6,
    "split_family_global_exact_route": 1,
    "split_family_global_exact_page": 5,
    "material_state_exact_route_action": 125,
    "material_state_combined_route_actions_exact": 119,
    "component_overlay_exact_route_actions": 58,
    "material_state_named_route_set_exact_ownership": 66,
    "custom_component_exact_route_actions": 57,
    "component_callback_exact_route_action": 44,
    "hero_component_exact_route_actions": 21,
    "material_state_all_routes_exact_intersection": 1,
    "safe_route_exact_route_owner_page_anchor_rejected": 1,
    "unresolved_split_family_page_ambiguous": 245,
    "unresolved_split_source_family": 90,
    "unresolved_no_manifest_lineage": 265,
})
require(status_counts == expected_status, f"Visual reconciliation status drift: {status_counts}")
require(len(result_rows) == 8753, "Result row count changed")
require([row["visual_id"] for row in result_rows] == [row["visual_id"] for row in original_rows], "Visual row order changed")

for original, result in zip(original_rows, result_rows):
    require(result["legacy_feature_id"] == original["feature_id"], "Legacy feature ID not preserved")
    for field in original_fields:
        if field != "feature_id":
            expected = (
                {"Observed-light": "Observed", "Dead/unreachable": "Source-inferred"}.get(
                    original[field], original[field]
                )
                if field == "classification"
                else original[field]
            )
            require(result[field] == expected, f"Observation field changed: {result['visual_id']} {field}")

allowed_classifications = {"Observed", "Source-inferred", "Not safely reproducible", "Blocked"}
require(
    {row["classification"] for row in result_rows} <= allowed_classifications,
    "Visual matrix contains a browser-claim classification outside the permitted vocabulary",
)

temp = MATRIX.with_suffix(".csv.tmp")
with temp.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=new_fields, lineterminator="\n")
    writer.writeheader()
    writer.writerows(result_rows)

with temp.open("r", encoding="utf-8-sig", newline="") as handle:
    reparsed = list(csv.DictReader(handle))
require(len(reparsed) == 8753 and len({row["visual_id"] for row in reparsed}) == 8753, "Reparsed visual matrix failed")
require(
    sha(temp) == EXPECTED_VISUAL_WAVE12_MATRIX_SHA,
    f"Wave-12 visual matrix SHA drift: {sha(temp)}",
)
temp.replace(MATRIX)
require(
    sha(MATRIX) == EXPECTED_VISUAL_WAVE12_MATRIX_SHA,
    f"Installed Wave-12 visual matrix SHA drift: {sha(MATRIX)}",
)

tuple_lines = [
    "\x1f".join((row["visual_id"], row["legacy_feature_id"], row["feature_id"], row["working_feature_ids"], row["feature_link_status"], row["feature_link_evidence"]))
    for row in result_rows
]
tuple_sha = hashlib.sha256("\n".join(tuple_lines).encode("utf-8")).hexdigest()
require(
    tuple_sha == "12b8f618832464c563869d259dd419a38813aba3f19e09806f6059b6aebf96a3",
    f"Semantic tuple SHA drift: {tuple_sha}",
)

material_route_patch_lines: list[str] = []
for original, result in zip(original_rows, result_rows):
    if result["feature_link_status"] != "material_state_exact_route_action":
        continue
    exact_routes = routes_by_pair[(original["route_name"], original["route_path"])]
    require(len(exact_routes) == 1, f"Material route patch lost exact route: {original['visual_id']}")
    exact_route = exact_routes[0]
    material_route_patch_lines.append("\x1f".join((
        original["visual_id"],
        result["feature_id"],
        str(exact_route["route_id"]),
        original["route_name"],
        original["route_path"],
        str(exact_route.get("action", "")),
        original["component_anchor"],
        original.get("state", ""),
    )))
material_route_patch_lines.sort()
material_route_patch_sha = hashlib.sha256(
    "\n".join(material_route_patch_lines).encode("utf-8")
).hexdigest()
require(len(material_route_patch_lines) == 125, "Material route patch count drift")
require(
    material_route_patch_sha == "baae6c620b6cf33cd70a69047ca1a3c912d86bc694966094d1c13147d36321ae",
    "Material route patch SHA drift",
)

require(len(wave3_promotions) == 157, "Wave-3 promotion count drift")
require(
    len({row["visual_id"] for row in wave3_promotions}) == 157,
    "Wave-3 visual IDs are not unique",
)
require(
    Counter(row["proposed_status"] for row in wave3_promotions)
    == Counter({
        "material_state_combined_route_actions_exact": 119,
        "component_overlay_exact_route_actions": 38,
    }),
    "Wave-3 promotion type drift",
)

wave3_patch_lines = sorted(
    "\x1f".join((
        row["visual_id"],
        row["target"],
        row["proposed_status"],
        row["route_name"],
        row["route_path"],
        row["route_ids"],
        row["actions"],
        row["component_anchor"],
        row["component_scope"],
    ))
    for row in wave3_promotions
)
wave3_patch_sha = hashlib.sha256(
    "\n".join(wave3_patch_lines).encode("utf-8")
).hexdigest()
require(
    wave3_patch_sha == "738d7c6f8ac3e0c9b1f4e8ee393e932cb709db66fe05cbb3c357636f7f3ef0a9",
    f"Wave-3 patch-map SHA drift: {wave3_patch_sha}",
)

wave3_assignment_lines = sorted(
    "\x1f".join((
        row["visual_id"],
        row["target"],
        row["proposed_status"],
    ))
    for row in wave3_promotions
)
wave3_assignment_sha = hashlib.sha256(
    "\n".join(wave3_assignment_lines).encode("utf-8")
).hexdigest()
require(
    wave3_assignment_sha == "16ae3afa7693e9c6a0ee0cc161d6c1a8eda56f6ff08cedaf8298359259c5f670",
    f"Wave-3 assignment SHA drift: {wave3_assignment_sha}",
)

require(len(wave4_promotions) == 66, "Wave-4 promotion count drift")
require(len({row["visual_id"] for row in wave4_promotions}) == 66, "Wave-4 visual IDs are not unique")
require(len({row["target"] for row in wave4_promotions}) == 34, "Wave-4 target count drift")
require(Counter(row["ownership"].split(":", 1)[0] for row in wave4_promotions) == Counter({"controller": 12, "page": 54}), "Wave-4 owner count drift")
wave4_prior_status_counts = Counter(row["prior_status"] for row in wave4_promotions)
require(wave4_prior_status_counts == Counter({"unresolved_no_manifest_lineage": 45, "unresolved_split_family_page_ambiguous": 20, "unresolved_split_source_family": 1}), f"Wave-4 prior-status count drift: {wave4_prior_status_counts}")
require({row["classification"] for row in wave4_promotions} == {"Not safely reproducible"}, "Wave-4 classification drift")
wave4_patch_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"], row["route_name"], row["route_path"], row["route_ids"], row["actions"], row["component_anchor"], row["ownership"])) for row in wave4_promotions)
wave4_patch_sha = hashlib.sha256("\n".join(wave4_patch_lines).encode("utf-8")).hexdigest()
require(wave4_patch_sha == "93644bc660c467362b4832d6dafa118b9b2e8ffc732da6f99ee00ae77f1169d6", f"Wave-4 patch SHA drift: {wave4_patch_sha}")
wave4_assignment_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"])) for row in wave4_promotions)
wave4_assignment_sha = hashlib.sha256("\n".join(wave4_assignment_lines).encode("utf-8")).hexdigest()
require(wave4_assignment_sha == "cdd51968e9026623ceca004003d0307d142e9d191218d4561fb559ff4a1d4c37", f"Wave-4 assignment SHA drift: {wave4_assignment_sha}")

require(len(wave5_promotions) == 39, "Wave-5 promotion count drift")
require(len({row["visual_id"] for row in wave5_promotions}) == 39, "Wave-5 visual IDs are not unique")
require(len({row["target"] for row in wave5_promotions}) == 34, "Wave-5 target count drift")
require(Counter(row["prior_status"] for row in wave5_promotions) == Counter({"unresolved_no_manifest_lineage": 24, "unresolved_split_family_page_ambiguous": 12, "unresolved_split_source_family": 3}), "Wave-5 prior-status drift")
wave5_assignment_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"])) for row in wave5_promotions)
wave5_assignment_sha = hashlib.sha256("\n".join(wave5_assignment_lines).encode("utf-8")).hexdigest()
require(wave5_assignment_sha == "6361287d71bc13f55b0e576617ea654b84c03647eafac6ebd92b12f7aebd6576", f"Wave-5 assignment SHA drift: {wave5_assignment_sha}")
wave5_proof_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"], row["prior_status"], row["callsite"], row["component"], row["component_file"], row["component_lines"], row["component_file_sha256"], row["route_ids"], row["route_names"], row["route_paths"], row["actions"])) for row in wave5_promotions)
wave5_proof_sha = hashlib.sha256("\n".join(wave5_proof_lines).encode("utf-8")).hexdigest()
require(wave5_proof_sha == "6ef08e74212cfd769585e153e370fb8a8169d0f4e92e6bfaefc79eb30e219fb0", f"Wave-5 proof-map SHA drift: {wave5_proof_sha}")

require(len(wave6_promotions) == 17, "Wave-6 promotion count drift")
require(len({row["visual_id"] for row in wave6_promotions}) == 17, "Wave-6 visual IDs are not unique")
require(len({row["target"] for row in wave6_promotions}) == 13, "Wave-6 target count drift")
require(Counter(row["proposed_status"] for row in wave6_promotions) == Counter({"custom_component_exact_route_actions": 7, "component_callback_exact_route_action": 10}), "Wave-6 status count drift")
require(Counter(row["classification"] for row in wave6_promotions) == Counter({"Source-inferred": 12, "Blocked": 5}), "Wave-6 classification drift")
wave6_assignment_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"])) for row in wave6_promotions)
wave6_assignment_sha = hashlib.sha256("\n".join(wave6_assignment_lines).encode("utf-8")).hexdigest()
require(wave6_assignment_sha == "96fae7a171a540ec5eed8c9ffbd3e9246a4ec22c7c11d221d2c7d61e00c57eab", f"Wave-6 assignment SHA drift: {wave6_assignment_sha}")
wave6_proof_lines = sorted("\x1f".join((row["visual_id"], row["prior_status"], row["prior_candidates"], row["target"], row["proposed_status"], row["proof_kind"], row["anchor"], row["source"], row["scope"], row["source_sha256"], row["route_ids"], row["route_names"], row["route_paths"], row["actions"], row["classification"])) for row in wave6_promotions)
wave6_proof_sha = hashlib.sha256("\n".join(wave6_proof_lines).encode("utf-8")).hexdigest()
require(wave6_proof_sha == "545643a84f470865e845263e2b542a4f0597864129ac3237013da4bd527e71b9", f"Wave-6 root proof-map SHA drift: {wave6_proof_sha}")

require(len(wave7_promotions) == 22, "Wave-7 promotion count drift")
require(len({row["visual_id"] for row in wave7_promotions}) == 22, "Wave-7 visual IDs are not unique")
require(len({row["target"] for row in wave7_promotions}) == 16, "Wave-7 target count drift")
require(Counter(row["proposed_status"] for row in wave7_promotions) == Counter({"component_overlay_exact_route_actions": 11, "component_callback_exact_route_action": 11}), "Wave-7 status count drift")
require(Counter(row["classification"] for row in wave7_promotions) == Counter({"Source-inferred": 17, "Blocked": 5}), "Wave-7 classification drift")
wave7_selected_lines = sorted(row["visual_id"] for row in wave7_promotions)
wave7_selected_sha = hashlib.sha256("\n".join(wave7_selected_lines).encode("utf-8")).hexdigest()
require(wave7_selected_sha == "030c3292122913e5bbc259e82277779e10747bef16b20cdeb63c2c984257eeda", f"Wave-7 selected-ID SHA drift: {wave7_selected_sha}")
wave7_assignment_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"])) for row in wave7_promotions)
wave7_assignment_sha = hashlib.sha256("\n".join(wave7_assignment_lines).encode("utf-8")).hexdigest()
require(wave7_assignment_sha == "a44ccd4b71ee9ddd5319c260e9b576753760df11367787d23e215f4dd17e879b", f"Wave-7 assignment SHA drift: {wave7_assignment_sha}")
wave7_proof_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"], row["prior_status"], row["prior_candidates"], row["classification"], row["component_anchor"], row["visual_anchor_source"], row["visual_anchor_source_sha256"], row["proof_source"], row["source_sha256"], row["source_scope"], row["binding_loci"], row["route_ids"], row["route_names"], row["route_paths"], row["actions"], row["controller_loci"], row["controller_hashes"])) for row in wave7_promotions)
wave7_proof_sha = hashlib.sha256("\n".join(wave7_proof_lines).encode("utf-8")).hexdigest()

require(len(wave8_promotions) == 41, "Wave-8 promotion count drift")
require(len({row["visual_id"] for row in wave8_promotions}) == 41, "Wave-8 visual IDs are not unique")
require(len({row["target"] for row in wave8_promotions}) == 23, "Wave-8 target count drift")
require(Counter(row["proposed_status"] for row in wave8_promotions) == Counter({"component_callback_exact_route_action": 21, "component_overlay_exact_route_actions": 9, "custom_component_exact_route_actions": 11}), "Wave-8 status count drift")
require(Counter(row["classification"] for row in wave8_promotions) == Counter({"Source-inferred": 31, "Blocked": 10}), "Wave-8 classification drift")
require(Counter(row["prior_status"] for row in wave8_promotions) == Counter({"unresolved_no_manifest_lineage": 22, "unresolved_split_family_page_ambiguous": 16, "unresolved_split_source_family": 3}), "Wave-8 prior-status drift")
wave8_selected_lines = sorted(row["visual_id"] for row in wave8_promotions)
wave8_selected_sha = hashlib.sha256("\n".join(wave8_selected_lines).encode("utf-8")).hexdigest()
require(wave8_selected_sha == "889d4d990abb74b8a1c7420c7b504c1d11d566f08d28c757c2e2c101533ac559", f"Wave-8 selected-ID SHA drift: {wave8_selected_sha}")
wave8_assignment_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"])) for row in wave8_promotions)
wave8_assignment_sha = hashlib.sha256("\n".join(wave8_assignment_lines).encode("utf-8")).hexdigest()
require(wave8_assignment_sha == "2ab6149d754a87c580b5f13ddb868e3505f72514ac3d93c41e34282e4f2ff8f4", f"Wave-8 assignment SHA drift: {wave8_assignment_sha}")
wave8_proof_lines = sorted("\x1f".join((row["visual_id"], row["target"], row["proposed_status"], row["prior_status"], row["prior_candidates"], row["classification"], row["component_anchor"], row["proof_source"], row["source_sha256"], row["scope"], row["route_ids"], row["route_names"], row["route_paths"], row["actions"])) for row in wave8_promotions)
wave8_proof_sha = hashlib.sha256("\n".join(wave8_proof_lines).encode("utf-8")).hexdigest()
require(wave8_proof_sha == "2e111b00ac27ba6cea1bd87ab723b7e5f49bfd5f9a006b91a227e6b7d81d6f18", f"Wave-8 proof-map SHA drift: {wave8_proof_sha}")

require(len(wave9_promotions) == 21, "Wave-9 promotion count drift")
require(len({row["visual_id"] for row in wave9_promotions}) == 21, "Wave-9 visual IDs are not unique")
require(len({row["target"] for row in wave9_promotions}) == 18, "Wave-9 target count drift")
require(
    Counter(row["proposed_status"] for row in wave9_promotions)
    == Counter({"hero_component_exact_route_actions": 21}),
    "Wave-9 status count drift",
)
require(
    Counter(row["classification"] for row in wave9_promotions)
    == Counter({"Source-inferred": 21}),
    "Wave-9 classification drift",
)
require(
    Counter(row["prior_status"] for row in wave9_promotions)
    == Counter({
        "unresolved_no_manifest_lineage": 6,
        "unresolved_split_family_page_ambiguous": 15,
    }),
    "Wave-9 prior-status drift",
)
wave9_selected_lines = sorted(row["visual_id"] for row in wave9_promotions)
wave9_selected_sha = hashlib.sha256("\n".join(wave9_selected_lines).encode("utf-8")).hexdigest()
require(
    wave9_selected_sha == "89c4ead2991fa6bbd233b7d94fddd0822470a9813f1dac48b64560162b18d762",
    f"Wave-9 selected-ID SHA drift: {wave9_selected_sha}",
)
wave9_assignment_lines = sorted(
    "\x1f".join((row["visual_id"], row["target"], row["proposed_status"]))
    for row in wave9_promotions
)
wave9_assignment_sha = hashlib.sha256("\n".join(wave9_assignment_lines).encode("utf-8")).hexdigest()
require(
    wave9_assignment_sha == "382b6fd98bdad04d98ee979f302545c534032db424020a2db81b63142a0e1342",
    f"Wave-9 assignment SHA drift: {wave9_assignment_sha}",
)
wave9_proof_lines = sorted(
    "\x1f".join((
        row["visual_id"], row["target"], row["proposed_status"], row["prior_status"],
        row["classification"], row["anchor_source"], row["anchor_sha256"],
        row["owner_source"], row["owner_sha256"], row["route_ids"], row["route_names"],
        row["route_paths"], row["actions"], row["controller_proofs"], row["scope"],
    ))
    for row in wave9_promotions
)
wave9_proof_sha = hashlib.sha256("\n".join(wave9_proof_lines).encode("utf-8")).hexdigest()
require(
    wave9_proof_sha == "8d2612019e4bf964b168d60b85c6931576a33d869ca5c0428058f01c5028595c",
    f"Wave-9 proof-map SHA drift: {wave9_proof_sha}",
)

require(len(wave10_promotions) == 2, "Wave-10 promotion count drift")
require(len({row["visual_id"] for row in wave10_promotions}) == 2, "Wave-10 visual IDs are not unique")
require(len({row["target"] for row in wave10_promotions}) == 2, "Wave-10 target count drift")
require(
    Counter(row["proposed_status"] for row in wave10_promotions)
    == Counter({"component_callback_exact_route_action": 2}),
    "Wave-10 status count drift",
)
wave10_selected_lines = sorted(row["visual_id"] for row in wave10_promotions)
wave10_selected_sha = hashlib.sha256("\n".join(wave10_selected_lines).encode("utf-8")).hexdigest()
require(
    wave10_selected_sha == "cbba08321469d4fe194962dd6656eb608eaadf422a9db57cae423ee91eb9069e",
    f"Wave-10 selected-ID SHA drift: {wave10_selected_sha}",
)
wave10_assignment_lines = sorted(
    "\x1f".join((row["visual_id"], row["target"], row["proposed_status"]))
    for row in wave10_promotions
)
wave10_assignment_sha = hashlib.sha256("\n".join(wave10_assignment_lines).encode("utf-8")).hexdigest()
require(
    wave10_assignment_sha == "ac591b64f3417b95f1fea7bcd9d39427f50e828c7c87096f7ab4c48b74458fbd",
    f"Wave-10 assignment SHA drift: {wave10_assignment_sha}",
)
wave10_proof_lines = sorted(
    "\x1f".join((
        row["visual_id"], row["target"], row["proposed_status"], row["route_id"],
        row["route_name"], row["route_path"], row["controller_action"],
        row["component_anchor"], row["binding"], row["component_sha256"],
        row["controller_sha256"],
    ))
    for row in wave10_promotions
)
wave10_proof_sha = hashlib.sha256("\n".join(wave10_proof_lines).encode("utf-8")).hexdigest()
require(
    wave10_proof_sha == "636d6c5c488c6d005fc8d9daaad2cc5766168f531179af66f7db3212c36df27f",
    f"Wave-10 proof-map SHA drift: {wave10_proof_sha}",
)

require(len(wave11_promotions) == 1, "Wave-11 promotion count drift")
require(wave11_promotions[0]["visual_id"] == "VIS-020353", "Wave-11 visual ID drift")
require(wave11_promotions[0]["target"] == "CAP-OPS-ROSTERING-PLANNING", "Wave-11 target drift")
wave11_assignment_lines = sorted(
    "\x1f".join((str(row["visual_id"]), str(row["target"]), str(row["proposed_status"])))
    for row in wave11_promotions
)
wave11_assignment_sha = hashlib.sha256("\n".join(wave11_assignment_lines).encode("utf-8")).hexdigest()
require(
    wave11_assignment_sha == "472327835e92d95405c86d3e0211ef0b698f9bca58b634be36fae05067ba3c81",
    f"Wave-11 assignment SHA drift: {wave11_assignment_sha}",
)
wave11_proof_lines = sorted(
    "\x1f".join((
        str(row["visual_id"]), str(row["target"]), str(row["proposed_status"]),
        str(row["prior_status"]), str(row["prior_candidates"]), str(row["route_ids"]),
        str(row["route_names"]), str(row["route_paths"]), str(row["route_actions"]),
        str(row["controller_source"]), str(row["controller_sha256"]),
    ))
    for row in wave11_promotions
)
wave11_proof_sha = hashlib.sha256("\n".join(wave11_proof_lines).encode("utf-8")).hexdigest()
require(
    wave11_proof_sha == "db5e36fa3b063fa80d8b560c7ecd2e78dfa6d2c556956ebe3c8fa927c74ec65c",
    f"Wave-11 proof-map SHA drift: {wave11_proof_sha}",
)

require(len(wave12_promotions) == 1, "Wave-12 promotion count drift")
require(wave12_promotions[0]["visual_id"] == "VIS-002282", "Wave-12 visual ID drift")
require(wave12_promotions[0]["target"] == "CAP-OPS-ROSTERING-PLANNING", "Wave-12 target drift")
wave12_assignment_lines = sorted(
    "\x1f".join((row["visual_id"], row["target"], row["proposed_status"]))
    for row in wave12_promotions
)
wave12_assignment_sha = hashlib.sha256("\n".join(wave12_assignment_lines).encode("utf-8")).hexdigest()
require(
    wave12_assignment_sha == "89289cd2dcf1c3a1bd2849a44a764dcfe2e0735411c63d236b8852660faab128",
    f"Wave-12 assignment SHA drift: {wave12_assignment_sha}",
)
wave12_proof_lines = sorted(
    "\x1f".join((
        row["visual_id"], row["target"], row["proposed_status"], row["prior_status"],
        row["prior_candidates"], row["route_id"], row["route_name"], row["route_path"],
        row["route_action"], row["rejected_page_id"], row["rejected_page_target"],
        row["rejected_component_anchor"], row["controller_source"], row["controller_sha256"],
    ))
    for row in wave12_promotions
)
wave12_proof_sha = hashlib.sha256("\n".join(wave12_proof_lines).encode("utf-8")).hexdigest()
require(
    wave12_proof_sha == "c318ed75d590e8519ead9c3c0be28ec3d25da75436ea37c92c85501df97d7c07",
    f"Wave-12 proof-map SHA drift: {wave12_proof_sha}",
)

unresolved_identity_lines = sorted(
    "\x1f".join((
        row["visual_id"],
        row["feature_link_status"],
        row["legacy_feature_id"],
        row["working_feature_ids"],
        row.get("route_name", ""),
        row.get("route_path", ""),
        row.get("component_anchor", ""),
        row.get("pattern_type", ""),
    ))
    for row in result_rows
    if not row["feature_id"]
)
unresolved_identity_sha = hashlib.sha256(
    "\n".join(unresolved_identity_lines).encode("utf-8")
).hexdigest()
require(len(unresolved_identity_lines) == 600, f"Unresolved identity count drift: {len(unresolved_identity_lines)}")
require(
    unresolved_identity_sha == "5ab1622516007f174c2ad3fd685ef33f68b9e5f7f6f0d71e60eebb9c599b000a",
    f"Unresolved identity SHA drift: {unresolved_identity_sha}",
)

assigned = sum(bool(row["feature_id"]) for row in result_rows)
unresolved = len(result_rows) - assigned
represented_manifest_ids = {candidate for row in result_rows for candidate in row["working_feature_ids"].split("|") if candidate}
summary = {
    "schema_version": "1.0",
    "artifact": "final-902-visual-link-generation-summary",
    "audited_commit": EXPECTED_COMMIT,
    "status": "partial_final_id_linkage_observations_preserved_runtime_coverage_unchanged",
    "audit_boundary": "Audit artifacts only; no application code, configuration, data, tests, browser state, deployment or Git history changed.",
    "inputs": {
        "visual_matrix_input_sha256": matrix_input_sha,
        "visual_matrix_original_sha256": EXPECTED_MATRIX_SHA,
        "manifest_sha256": EXPECTED_MANIFEST_SHA,
        "inventory_sha256": EXPECTED_INVENTORY_SHA,
    },
    "counts": {
        "rows": 8753,
        "unique_visual_ids": 8753,
        "assigned_final_feature_id": assigned,
        "unresolved_final_feature_id": unresolved,
        "unique_assigned_final_feature_ids": len({row["feature_id"] for row in result_rows if row["feature_id"]}),
        "manifest_ids_with_any_visual_lineage": len(represented_manifest_ids),
        "manifest_ids_without_visual_lineage": 902 - len(represented_manifest_ids),
        "unresolved_with_manifest_candidates": sum(not row["feature_id"] and bool(row["working_feature_ids"]) for row in result_rows),
        "unresolved_without_manifest_candidates": sum(not row["working_feature_ids"] for row in result_rows),
        "status_counts": dict(sorted(status_counts.items())),
        "classification_counts": dict(sorted(Counter(row["classification"] for row in result_rows).items())),
        "pattern_type_counts": dict(sorted(Counter(row["pattern_type"] for row in result_rows).items())),
    },
    "outputs": {
        "matrix": "../../05-browser-visual-coverage-matrix.csv",
        "matrix_sha256": sha(MATRIX),
        "semantic_tuple_sha256": tuple_sha,
        "semantic_tuple_algorithm": "Current row order; UTF-8; LF/no trailing LF; visual_id US legacy_feature_id US feature_id US working_feature_ids US feature_link_status US feature_link_evidence",
    },
    "proof_boundary": (
        "Every original observation is preserved. Four browser-measured Observed-light labels are "
        "normalized to Observed; five static Dead/unreachable source conclusions are normalized to "
        "Source-inferred; their evidence notes remain unchanged. Blank final feature IDs are explicit "
        "unresolved links. Source-family envelopes are never promoted to split-target proof. Missing or "
        "stale lineage is bypassed only when an exact current route/page relation, a positionally paired "
        "combined route/action envelope, a component-owned complete set of direct literal mutations, an "
        "exact source-pinned component callback binding, an exact bounded hero action, or a strict all-route "
        "target intersection, or an exact route owner with a conflicting stale page anchor explicitly rejected, proves one unique "
        "current target with current backend "
        "method/source anchors. These additions prove "
        "final-ID lineage only; browser/runtime coverage and row classifications are unchanged."
    ),
    "material_state_exact_route_action_wave": {
        "count": 125,
        "classification": "Not safely reproducible",
        "selection_rule": "Material-state applicability + exact source-applicability map + exact current route name/path + one inventory target equal to one manifest route target + existing frontend source + existing backend controller method.",
        "patch_map_sha256": material_route_patch_sha,
        "patch_map_algorithm": "Lexicographic line sort; UTF-8; LF/no trailing LF; visual_id US target US route_id US route_name US route_path US action US component_anchor US state",
        "unresolved_remainder_sha256": unresolved_identity_sha,
        "unresolved_remainder_algorithm": "Lexicographic line sort; UTF-8; LF/no trailing LF; visual_id US current_status US legacy_feature_id US working_feature_ids US route_name US route_path US component_anchor US pattern_type",
        "claim_limit": "This proves exact target lineage for route-specific material-state rows; it does not convert any row into runtime/browser completion."
    },
    "proof_safe_final_id_wave3": {
        "count": 157,
        "combined_route_envelopes": 119,
        "component_owned_overlays": 38,
        "classification_changed": False,
        "patch_map_sha256": wave3_patch_sha,
        "patch_map_algorithm": (
            "Lexicographic line sort; UTF-8; LF/no trailing LF; "
            "visual_id US target US proposed_status US route_name US route_path "
            "US route_ids US actions US component_anchor US component_scope"
        ),
        "assignment_sha256": wave3_assignment_sha,
        "assignment_algorithm": (
            "Lexicographic line sort; UTF-8; LF/no trailing LF; "
            "visual_id US target US proposed_status"
        ),
        "claim_limit": (
            "Final-ID lineage only. No row is promoted to runtime/browser proof, "
            "and no source-family or shared-page envelope is inherited."
        ),
    },
    "proof_safe_final_id_wave4": {
        "count": 66,
        "unique_targets": 34,
        "controller_owned": 12,
        "page_owned": 54,
        "classification_changed": False,
        "patch_map_sha256": wave4_patch_sha,
        "assignment_sha256": wave4_assignment_sha,
        "claim_limit": "Final-ID lineage only; all 66 rows remain Not safely reproducible.",
    },
    "proof_safe_final_id_wave5": {
        "count": 39,
        "unique_targets": 34,
        "classification_changed": False,
        "proof_map_sha256": wave5_proof_sha,
        "assignment_sha256": wave5_assignment_sha,
        "claim_limit": "Final-ID lineage only; no runtime/browser or material-state completion credit.",
    },
    "proof_safe_final_id_wave6": {
        "count": 17,
        "unique_targets": 13,
        "named_component_proofs": 7,
        "exact_callback_proofs": 10,
        "classification_changed": False,
        "proof_map_sha256": wave6_proof_sha,
        "assignment_sha256": wave6_assignment_sha,
        "full_effective_map_sha256": "2908852e1c13a5d0ff86abc164e71fef333019ecdd36a17098dd3fa2eef3a3d2",
        "claim_limit": "Final-ID lineage only; source bytes, component/callback bindings, routes, targets and controller methods are pinned. No runtime/browser or material-state completion credit.",
    },
    "proof_safe_final_id_wave7": {
        "count": 22,
        "unique_targets": 16,
        "component_overlay_proofs": 11,
        "exact_callback_proofs": 11,
        "classification_changed": False,
        "selected_visual_ids_sha256": wave7_selected_sha,
        "assignment_sha256": wave7_assignment_sha,
        "proof_map_sha256": wave7_proof_sha,
        "claim_limit": "Final-ID lineage only; source bytes, component/callback bindings, routes, targets and controller methods are pinned. No runtime/browser, material-state or completion credit.",
    },
    "proof_safe_final_id_wave8": {
        "count": 41,
        "unique_targets": 23,
        "component_callback_proofs": 21,
        "component_overlay_proofs": 9,
        "custom_component_proofs": 11,
        "classification_changed": False,
        "selected_visual_ids_sha256": wave8_selected_sha,
        "assignment_sha256": wave8_assignment_sha,
        "proof_map_sha256": wave8_proof_sha,
        "claim_limit": "Final-ID lineage only; exact source bytes, visual anchors, routes, targets and controller actions are pinned. No runtime/browser, material-state or completion credit.",
    },
    "proof_safe_final_id_wave9": {
        "count": 21,
        "unique_targets": 18,
        "hero_component_action_proofs": 21,
        "classification_changed": False,
        "selected_visual_ids_sha256": wave9_selected_sha,
        "assignment_sha256": wave9_assignment_sha,
        "proof_map_sha256": wave9_proof_sha,
        "claim_limit": "Final-ID lineage only; exact hero/action owner source bytes, routes, targets and controller methods are pinned. No runtime/browser, material-state or completion credit.",
    },
    "proof_safe_final_id_wave10": {
        "count": 2,
        "unique_targets": 2,
        "component_callback_proofs": 2,
        "classification_changed": False,
        "selected_visual_ids_sha256": wave10_selected_sha,
        "assignment_sha256": wave10_assignment_sha,
        "proof_map_sha256": wave10_proof_sha,
        "claim_limit": "Final-ID lineage only; exact callback bindings, component/controller source bytes, routes and sole targets are pinned. No runtime/browser, material-state or completion credit.",
    },
    "proof_safe_final_id_wave11": {
        "count": 1,
        "unique_targets": 1,
        "all_route_intersection_proofs": 1,
        "classification_changed": False,
        "assignment_sha256": wave11_assignment_sha,
        "proof_map_sha256": wave11_proof_sha,
        "claim_limit": "Final-ID lineage only; every exact route's current target set has one common target, with controller source/action pins. No runtime/browser, material-state or completion credit.",
    },
    "proof_safe_final_id_wave12": {
        "count": 1,
        "unique_targets": 1,
        "exact_route_rejected_page_proofs": 1,
        "classification_changed": False,
        "assignment_sha256": wave12_assignment_sha,
        "proof_map_sha256": wave12_proof_sha,
        "claim_limit": "Final-ID lineage only; the exact route/controller owner is pinned and the conflicting recorded page anchor is explicitly rejected. No browser/runtime or completion credit.",
    },
    "validation": {
        "row_count_preserved": True,
        "visual_id_order_preserved": True,
        "legacy_feature_id_preserved": True,
        "original_observation_fields_preserved_except_permitted_classification_normalization": True,
        "browser_claim_classification_vocabulary_conforming": True,
        "assigned_ids_exist_in_manifest": True,
        "unresolved_rows_have_blank_final_id": True,
        "proof_safe_wave3_count_and_type_verified": True,
        "proof_safe_wave3_visual_ids_unique": True,
        "proof_safe_wave3_classifications_unchanged": True,
        "proof_safe_wave4_count_owner_and_status_verified": True,
        "proof_safe_wave4_visual_ids_unique": True,
        "proof_safe_wave4_classifications_unchanged": True,
        "proof_safe_wave5_count_target_and_status_verified": True,
        "proof_safe_wave5_visual_ids_unique": True,
        "proof_safe_wave5_classifications_unchanged": True,
        "proof_safe_wave6_count_target_and_status_verified": True,
        "proof_safe_wave6_visual_ids_unique": True,
        "proof_safe_wave6_classifications_unchanged": True,
        "proof_safe_wave7_count_target_and_status_verified": True,
        "proof_safe_wave7_visual_ids_unique": True,
        "proof_safe_wave7_classifications_unchanged": True,
        "proof_safe_wave8_count_target_and_status_verified": True,
        "proof_safe_wave8_visual_ids_unique": True,
        "proof_safe_wave8_classifications_unchanged": True,
        "proof_safe_wave9_count_target_and_status_verified": True,
        "proof_safe_wave9_visual_ids_unique": True,
        "proof_safe_wave9_classifications_unchanged": True,
        "proof_safe_wave10_count_target_and_status_verified": True,
        "proof_safe_wave10_visual_ids_unique": True,
        "proof_safe_wave10_classifications_unchanged": True,
        "proof_safe_wave11_count_target_and_status_verified": True,
        "proof_safe_wave11_visual_ids_unique": True,
        "proof_safe_wave11_classifications_unchanged": True,
        "proof_safe_wave12_count_target_and_status_verified": True,
        "proof_safe_wave12_visual_ids_unique": True,
        "proof_safe_wave12_classifications_unchanged": True,
    },
    "completion_gate": {"complete": False, "reason": f"{unresolved} rows remain without a uniquely proved final working ID"},
}
SUMMARY.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(json.dumps({
    "rows": 8753,
    "assigned": assigned,
    "unresolved": unresolved,
    "unique_assigned_feature_ids": summary["counts"]["unique_assigned_final_feature_ids"],
    "matrix_sha256": sha(MATRIX),
    "semantic_tuple_sha256": tuple_sha,
    "summary_sha256": sha(SUMMARY),
}, indent=2))
