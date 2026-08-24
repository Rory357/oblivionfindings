#!/usr/bin/env python3
"""Reconcile fresh benchmark assignments RUN-007 through RUN-009.

This deterministic generator reads only committed audit evidence.  It does not
contact benchmark projects, boot Oblivion Findings, execute application code,
or award current benchmark/completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
from collections import Counter, defaultdict
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
HISTORICAL_DIR = AUDIT_DIR.parent / "oblivion-oss-comprehensive-audit-2026-08-12"
GENERATED_AT = "2026-08-24T17:26:22+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_INPUT_COMMIT = "ec11042ea09d7bc56dff5b992b4d6ebfb03ad9df"
PROJECT_REGISTER_SHA256 = "95fbfdf22b0acb6204a334677422ce5c0145621e268b8e112ee0249413169ffe"
WAVE_01_SHA256 = "c422cfd9e4005c083518abe9e8837c16740e8797b249ffdd2a9f9e4e00ad2aeb"
WAVE_02_SHA256 = "3a9404e19db17d46b88b13bf545f22e1fe4897b41cc119341f56197a8b321a71"
HISTORICAL_904_SHA256 = "659dc53cd3f8438c0c699b17d7579c449f741081f963956b2c941183905717b7"
HISTORICAL_902_SHA256 = "21f182c0a2d8cb3416ab7c8d54a27698673e324e0c7945ee9e5fb3a9a61b961f"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
PROMPT_URL_OCCURRENCE_MULTISET_SHA256 = "a11def3fd47294297fb8aac9b327287059e063aeb58ccd2045d8afb9347f49f5"
PROMPT_DUPLICATE_PROJECTS = {
    "glpi-project/glpi",
    "netbox-community/netbox",
    "opf/openproject",
}
HISTORICAL_EXTRA_PROJECTS = {
    "Bahmni/openmrs-module-ipd": "HISTORICAL_EXTRA_OUTSIDE_PROMPT",
    "medplum/medplum-provider": "HISTORICAL_EXTRA_OUTSIDE_PROMPT",
    "frappe/frappe": "SUPPLEMENTAL_OBSERVER_PROJECT_OUTSIDE_PROMPT",
}


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def write_csv(relative: str, fieldnames: list[str], rows: list[dict[str, object]]) -> None:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=fieldnames, lineterminator="\n", quoting=csv.QUOTE_MINIMAL)
    writer.writeheader()
    writer.writerows(rows)
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(buffer.getvalue(), encoding="utf-8", newline="\n")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def digest(payload: object) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


project_register_path = HISTORICAL_DIR / "06-open-source-benchmark-register.csv"
assert sha256_file(project_register_path) == PROJECT_REGISTER_SHA256
assert sha256_file(AUDIT_DIR / "evidence/source/current-feature-discovery-wave-01.json") == WAVE_01_SHA256
assert sha256_file(AUDIT_DIR / "evidence/source/current-feature-discovery-wave-02.json") == WAVE_02_SHA256

with project_register_path.open(encoding="utf-8", newline="") as handle:
    historical_reader = csv.DictReader(handle)
    historical_fields = list(historical_reader.fieldnames or [])
    historical_projects = list(historical_reader)

assert len(historical_projects) == 98
prompt_unique_projects = [row for row in historical_projects if row["project"] not in HISTORICAL_EXTRA_PROJECTS]
assert len(prompt_unique_projects) == 95
assert len({row["project"].lower() for row in prompt_unique_projects}) == 95
assert PROMPT_DUPLICATE_PROJECTS <= {row["project"] for row in prompt_unique_projects}
assert sum(2 if row["project"] in PROMPT_DUPLICATE_PROJECTS else 1 for row in prompt_unique_projects) == 98
project_index = {row["project"]: row for row in historical_projects}

wave1 = read_json("evidence/source/current-feature-discovery-wave-01.json")
wave2 = read_json("evidence/source/current-feature-discovery-wave-02.json")
candidates = wave1["candidates"] + wave2["candidates"]
assert len(candidates) == 172
assert len({row["candidate_id"] for row in candidates}) == 172
candidate_ids = {row["candidate_id"] for row in candidates}


# RUN-007 returned 30 provisional observer relations covering 29 current
# grouped candidates.  The relation label is evidence of the bounded agent
# return only; the historical project row is not refreshed or promoted.
OBSERVER_MAPPINGS = [
    ("CAP-OPS-CLIENT-RECORD-LIFECYCLE", "Bahmni/bahmni-core", "partial"),
    ("CAP-CLI-CLIENT-DOCUMENT-STAFF-LIBRARY", "paperless-ngx/paperless-ngx", "strong"),
    ("OPS-CLIENT-CONSENT", "ohcnetwork/care", "strong"),
    ("OPS-CLIENT-CONSENT", "ohcnetwork/care_fe", "strong"),
    ("CAP-CLIN-OBSERVATION-REGISTER-RECORD", "openemr/openemr", "strong"),
    ("CAP-MED-WORKER-TODAY-WORKLIST", "Bahmni/openmrs-module-ipd-frontend", "partial"),
    ("CAP-MED-WORKER-DOSE-PRN-LIFECYCLE", "Bahmni/openmrs-module-medicationadministration", "strong"),
    ("CAP-MED-EMAR-WORKSPACE-ORDER-LIFECYCLE", "Bahmni/openmrs-module-medicationadministration", "strong"),
    ("CAP-MED-REVIEW-COMPETENCY-ROUND-SELFADMIN", "moodle/moodle", "partial"),
    ("CAP-MED-DESTRUCTION-STOCK-PHARMACY", "inventree/InvenTree", "partial"),
    ("CAP-INC-INCIDENT-EVIDENCE-FOLLOWUP", "braedonsaunders/beaconhs", "partial"),
    ("CAP-INC-INCIDENT-REVIEW-CLOSURE", "MeyerThorsten/QAtrial", "partial"),
    ("CAP-INC-SAFEGUARDING-INVESTIGATION-TERMINAL", "MeyerThorsten/QAtrial", "partial"),
    ("CAP-HR-ONBOARDING-OFFBOARDING", "frappe/hrms", "partial"),
    ("CAP-HR-COMPLIANCE-VETTING-TRAINING", "moodle/moodle", "partial"),
    ("CAP-HR-LEAVE-TIME-PAYROLL", "horilla/horilla-hr", "partial"),
    ("CAP-HR-DOCUMENT-POLICY-SIGNATURE", "documenso/documenso", "partial"),
    ("CAP-HR-WEBHOOK-DELIVERY", "frappe/frappe", "partial"),
    ("CAP-OPS-SHIFT-STAFFING-COVER", "lennystepn-hue/schichtplaner", "strong"),
    ("CAP-OPS-ROSTER-PLAN-PUBLISH", "TimefoldAI/timefold-solver", "partial"),
    ("CAP-OPS-ATTENDANCE-CLOCK-SESSION", "orangehrm/orangehrm", "partial"),
    ("CAP-OPS-TIMESHEET-MANAGER-PAYROLL", "kimai/kimai", "partial"),
    ("CAP-OPS-STAFF-AVAILABILITY-TIME-OFF", "lennystepn-hue/schichtplaner", "partial"),
    ("CAP-HR-STAFF-ASSIGNMENT-CREDENTIAL", "frappe/hrms", "partial"),
    ("CAP-DAY-MY-DAY-WORKSPACE", "medic/cht-core", "partial"),
    ("CAP-DAY-ALL-TASKS-WORKBENCH", "medic/cht-core", "strong"),
    ("CAP-OPS-REPORTING-EXPORT", "apache/superset", "partial"),
    ("CAP-HR-REPORTING-EXPORT", "apache/superset", "partial"),
    ("CAP-CLIN-RECORD-WIZARD-CONTEXT-API", "openmrs/openmrs-esm-form-engine-lib", "partial"),
    ("CAP-CLIN-ASSESSMENT-PROTOCOL-LIFECYCLE", "openmrs/openmrs-esm-form-engine-lib", "partial"),
]
assert len(OBSERVER_MAPPINGS) == 30
assert len({row[0] for row in OBSERVER_MAPPINGS}) == 29
assert Counter(row[2] for row in OBSERVER_MAPPINGS) == {"partial": 22, "strong": 8}
assert all(candidate_id in candidate_ids for candidate_id, _, _ in OBSERVER_MAPPINGS)
assert all(project in project_index for _, project, _ in OBSERVER_MAPPINGS)

observer_records = []
for candidate_id, project, observer_strength in OBSERVER_MAPPINGS:
    project_row = project_index[project]
    observer_records.append(
        {
            "candidate_id": candidate_id,
            "project": project,
            "observer_strength": observer_strength,
            "project_url": project_row["canonical_url"],
            "historical_project_commit": project_row["commit_sha"],
            "historical_exact_behaviour_locus": project_row["exact_behaviour_screen_workflow_inspected"],
            "status": "PROVISIONAL_OBSERVER_RELATION_NOT_SELECTED_OR_PROMOTED",
            "evidence_limit": "RUN-007 relation plus committed-local historical project row; no 2026-08-24 upstream refresh, neutralized parity, runtime proof, or completion credit.",
        }
    )


NEUTRALIZER_ADJUDICATIONS = [
    ("CAP-OPS-CLIENT-RECORD-LIFECYCLE", "ohcnetwork/care", "FAIL_SEMANTIC_COLLISION", "An encounter workflow is not authoritative client-record identity and lifecycle governance."),
    ("OPS-CLIENT-CONSENT", "ohcnetwork/care_fe", "PARTIAL_REQUIREMENT_ONLY", "Structured consent capture does not prove representative authority, decision evidence, withdrawal, expiry, and downstream effects."),
    ("CAP-CLIN-MODULE-DASHBOARD", "openmrs/openmrs-esm-patient-chart", "FAIL_GENERIC_UI_COLLISION", "A dashboard component proves presentation, not approved-Site clinical scope or downstream action ownership."),
    ("CAP-CLIN-OBSERVATION-REGISTER-RECORD", "openemr/openemr", "SURVIVES_NARROW_STATIC_REQUIREMENT", "Patient/encounter-bound validated observation persistence survives narrowly; Oblivion Site and privacy proof does not transfer."),
    ("CAP-CLIN-EVENT-REGISTER-RECORD", "openmrs/openmrs-core", "SURVIVES_NARROW_STATIC_REQUIREMENT", "Person-context clinical-event persistence survives narrowly; Site/privacy parity does not transfer."),
    ("CAP-CLIN-EVENT-REVIEW-ESCALATION-CLOSURE", "OneUptime/oneuptime", "PARTIAL_REQUIREMENT_ONLY", "Generic alert escalation does not prove accountable clinical review, follow-up, safety closure, or clinical authority."),
    ("CAP-CLIN-RECORD-WIZARD-CONTEXT-API", "openmrs/openmrs-esm-patient-management", "PARTIAL_REQUIREMENT_ONLY", "Subject search/context supports selection only; governed payload, approved-Site scope, and wizard authority remain unproved."),
    ("CAP-DAY-ALL-TASKS-WORKBENCH", "opf/openproject", "FAIL_GENERIC_WORK_ITEM_COLLISION", "Generic work-package mechanics do not prove cross-module authority or canonical mutation delegation."),
    ("CAP-DAY-MY-CALENDAR", "frappe/hrms", "PARTIAL_REQUIREMENT_ONLY", "A worker event feed supports a narrow personal read need, not the full current calendar owner and scope."),
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "frappe/erpnext", "SURVIVES_NARROW_STATIC_REQUIREMENT", "Identity/status lifecycle mechanics survive narrowly; Site, privacy, invitation, rehire, and canonical-user convergence do not transfer."),
    ("CAP-INC-INCIDENT-REVIEW-CLOSURE", "braedonsaunders/beaconhs", "SURVIVES_NARROW_STATIC_REQUIREMENT", "Incident/action completion and closure evidence survive narrowly; Site, alert journey, reportability, and reopening remain native."),
    ("CAP-OPS-ATTENDANCE-CLOCK-SESSION", "kimai/kimai", "FAIL_SEMANTIC_COLLISION", "Timesheet locking and voter checks do not prove a live attendance clock/break/correction lifecycle."),
    ("CAP-OPS-SHIFT-STAFFING-COVER", "TimefoldAI/timefold-solver", "FAIL_SEMANTIC_COLLISION", "Planning scores do not prove assignment, cover acceptance, reservation, expiry, competency, or Site consequences."),
    ("CAP-GOV-AUDIT-LOG-EXPORT", "frappe/frappe", "PARTIAL_REQUIREMENT_ONLY", "Generic export/logging does not prove immutable cross-module governance evidence, redaction, approval, retention, or integrity."),
    ("CAP-PRIV-COMPLIANCE-REPORT-EXPORT", "intuitem/ciso-assistant-community", "SURVIVES_NARROW_STATIC_REQUIREMENT", "Accessible-object privacy export mechanics survive narrowly; Oblivion action authority and Site/object scope do not transfer."),
]
assert Counter(row[2].split("_", 1)[0] for row in NEUTRALIZER_ADJUDICATIONS) == {"FAIL": 5, "PARTIAL": 5, "SURVIVES": 5}
assert all(row[0] in candidate_ids for row in NEUTRALIZER_ADJUDICATIONS)

neutralizer_records = [
    {
        "candidate_id": candidate_id,
        "project": project,
        "neutralized_result": result,
        "reason": reason,
        "completion_credit": False,
    }
    for candidate_id, project, result, reason in NEUTRALIZER_ADJUDICATIONS
]

COLLISION_GROUPS = [
    ("CARE_PLAN_IDENTITY_OVERLAP", ["CAP-OPS-CARE-PLAN-LIFECYCLE", "CAP-OPS-CARE-PLAN-REVIEW-SIGNOFF"]),
    ("EVENT_INCIDENT_ALERT_TERMINAL_COLLISION", ["CAP-CLIN-EVENT-REVIEW-ESCALATION-CLOSURE", "CAP-INC-INCIDENT-REVIEW-CLOSURE", "CAP-HS-EVENT-CLOSURE-EXCEPTIONS", "CAP-SAFE-TERMINAL-PROJECTION", "CAP-CR-ALERT-WORKLIST-LIFECYCLE"]),
    ("REPORT_EXPORT_COLLISION", ["CAP-CLI-CLIENT-DOCUMENT-AUDIT-EXPORT", "CAP-MED-REPORT-PDF-AUDIT-EXPORTS", "CAP-INC-REPORT-AUDIT-EXPORTS", "CAP-HR-REPORTING-EXPORT", "CAP-OPS-REPORTING-EXPORT", "CAP-FIN-REPORT-AUDIT-EXPORT", "CAP-GOV-AUDIT-LOG-EXPORT", "CAP-PRIV-COMPLIANCE-REPORT-EXPORT", "CAP-HS-GOVERNANCE-REPORTS-EXPORT", "CAP-SEC-REPORTING-EXPORT"]),
    ("CALENDAR_SCHEDULE_SYNC_COLLISION", ["CAP-DAY-MY-CALENDAR", "CAP-SITE-CALENDAR-RESOURCE-SCHEDULING", "CAP-OPS-CALENDAR-SYNC", "CAP-OPS-ROSTER-PLAN-PUBLISH", "CAP-CLIN-ASSESSMENT-PROTOCOL-LIFECYCLE"]),
    ("DOCUMENT_EVIDENCE_COLLISION", ["CAP-CLI-CLIENT-DOCUMENT-STAFF-LIBRARY", "CAP-CLI-CLIENT-DOCUMENT-AUDIT-EXPORT", "CAP-HR-DOCUMENT-POLICY-SIGNATURE", "CAP-GOV-DOCUMENT-LIBRARY", "CAP-PRIV-EVIDENCE-ATTACHMENTS", "CAP-INC-EVIDENCE-DOWNLOADS"]),
    ("WORKFORCE_TIME_COLLISION", ["CAP-OPS-SHIFT-LIFECYCLE", "CAP-OPS-SHIFT-STAFFING-COVER", "CAP-OPS-ATTENDANCE-CLOCK-SESSION", "CAP-OPS-TIMESHEET-AUTHOR-SUBMIT", "CAP-OPS-TIMESHEET-MANAGER-PAYROLL", "CAP-OPS-STAFF-AVAILABILITY-TIME-OFF"]),
    ("DASHBOARD_READ_LENS_COLLISION", ["CAP-CLIN-MODULE-DASHBOARD", "CAP-CLIN-TRENDS-SUMMARY-CARE-LENS", "CAP-OPS-DASHBOARD-ACTIVITY", "CAP-FIN-DASHBOARD-INSIGHTS", "CAP-GOV-DASHBOARD-REPORT-EVIDENCE", "CAP-HS-DASHBOARD-ANALYTICS", "CAP-PRIV-DASHBOARD-WORKLIST", "CAP-DAY-MY-DAY-WORKSPACE"]),
    ("ASSET_SITE_DEVICE_COLLISION", ["CAP-SITE-PLAN-ROOM-HARDWARE", "CAP-FLEET-ASSET-VEHICLE-REGISTER", "CAP-FIN-FIXED-ASSET-LIFECYCLE", "CAP-SEC-DEVICE-REGISTRY-CUSTODY"]),
    ("COMPOSITE_CANDIDATE_COLLISION", ["CAP-MED-HANDOVER-BREAKGLASS-CORRECTION-ERROR", "CAP-HR-LEAVE-TIME-PAYROLL", "CAP-FIN-ACCOUNTING-SYNC-FX-CONSOLIDATION", "CAP-GOV-MEETING-AGENDA-MINUTES-ATTENDANCE", "CAP-CR-DEVICE-MAP-PLAYBOOK-SETTINGS"]),
]
assert len(COLLISION_GROUPS) == 9


COMPARATOR_RECORDS = [
    {
        "domain": "controlled_medications",
        "candidate_ids": ["CAP-MED-CD-REGISTER-BALANCE", "CAP-MED-DESTRUCTION-STOCK-PHARMACY"],
        "classification": "OBLIVION_SPECIFIC_GAP",
        "project": "openemr/openemr",
        "stronger_side": None,
        "result": "OpenEMR proves permission/CSRF-backed destruction fields and reporting but not NZ controlled-drug custody. Oblivion destruction is stock-locked transactionally, while register-entry plus stock and balance mutation locking remain incomplete current-source questions.",
        "anchors": ["routes/emar.php:211-231", "app/Http/Controllers/Emar/EmarController.php:4023-4119", "app/Http/Controllers/Emar/EmarController.php:4986-5237", "evidence/source/current-feature-discovery-wave-01.json"],
    },
    {
        "domain": "governance_resolution_vote_quorum",
        "candidate_ids": ["CAP-GOV-RESOLUTION-VOTE-QUORUM"],
        "classification": "OBLIVION_SPECIFIC_GAP",
        "project": "OpenSlides/OpenSlides",
        "stronger_side": None,
        "result": "OpenSlides proves internal vote creation but not a frozen eligible electorate. Oblivion source shows no canonical aggregate lock or close-time quorum gate and appears to calculate from live membership/attendance state.",
        "anchors": ["app/Domain/Governance/Services/VotingService.php:29-68", "app/Domain/Governance/Services/VotingService.php:106-164", "app/Domain/Governance/Models/Resolution.php:122-147", "app/Domain/Governance/Models/Resolution.php:186-212"],
    },
    {
        "domain": "governance_board_packs",
        "candidate_ids": ["CAP-GOV-BOARD-PACK-DISTRIBUTION"],
        "classification": "NO_CREDIBLE_COMPARISON",
        "project": "OpenSlides/OpenSlides",
        "stronger_side": None,
        "result": "OpenSlides does not establish immutable assembled-pack manifests, approval provenance, recipient distribution, or read acknowledgement. Oblivion has a manifest/distribution model, but current visibility and regeneration semantics remain provisional concerns.",
        "anchors": ["app/Domain/Governance/Http/Controllers/BoardPackController.php:23-90", "app/Domain/Governance/Http/Controllers/BoardPackController.php:194-247", "app/Domain/Governance/Services/BoardPackBuilderService.php:27-47", "app/Domain/Governance/Services/BoardPackBuilderService.php:256-287"],
    },
    {
        "domain": "health_safety_registers",
        "candidate_ids": ["CAP-HS-FIRST-AID-REGISTER", "CAP-HS-WORKER-PARTICIPATION", "CAP-HS-HAZARDOUS-SUBSTANCES-SDS", "CAP-HS-EMERGENCY-DRILLS", "CAP-HS-PPE-REGISTER"],
        "classification": "OBLIVION_SPECIFIC_GAP",
        "project": "braedonsaunders/beaconhs",
        "stronger_side": None,
        "result": "Comparators provide concrete CAPA, effectiveness, and risk/action mechanics but no approved-Site scoping parity. Representative Oblivion list/count paths use optional Site filters, leaving foreign-Site list/direct-object/picker/export/write risk for independent proof.",
        "anchors": ["app/Http/Controllers/HealthSafety/FirstAidController.php:55-85", "app/Http/Controllers/HealthSafety/WorkerParticipationController.php:63-147", "app/Http/Controllers/HealthSafety/PpeController.php:37-112"],
    },
    {
        "domain": "privacy_compliance_reports",
        "candidate_ids": ["CAP-PRIV-COMPLIANCE-REPORT-EXPORT"],
        "classification": "STRONGER_NATIVE_CONTROL",
        "project": "intuitem/ciso-assistant-community",
        "stronger_side": "CISO Assistant benchmark",
        "result": "CISO Assistant retains role-scoped accessible-object aggregation and export. Oblivion appears to use one broad privacy permission with unscoped aggregate queries, while its repository-native CSV formula sanitisation remains a strength.",
        "anchors": ["routes/privacy.php:145-155", "app/Http/Controllers/PrivacyReportController.php:21-94", "app/Http/Controllers/PrivacyReportController.php:109-198", "app/Http/Controllers/Concerns/SanitizesCsvOutput.php:32-64"],
    },
    {
        "domain": "safeguarding_intake_projections",
        "candidate_ids": ["CAP-SAFE-CONCERN-INTAKE-TRIAGE", "CAP-SAFE-TERMINAL-PROJECTION"],
        "classification": "NO_CREDIBLE_COMPARISON",
        "project": "primeroIMS/primero",
        "stronger_side": None,
        "result": "Primero supports case assignment, closure/reopen, and action plans but not Oblivion intake reconciliation, sensitivity/Site authority, projection identity, deduplication, or durable recovery. After-commit projection failures are logged while terminal transitions require existing projections.",
        "anchors": ["app/Http/Controllers/SafeguardingConcernController.php:213-258", "app/Observers/SafeguardingConcernObserver.php:19-36", "app/Observers/SafeguardingConcernObserver.php:166-188", "app/Services/Safeguarding/SafeguardingTerminalTransitionService.php:103-348"],
    },
    {
        "domain": "outbound_webhook_destinations",
        "candidate_ids": ["CAP-INT-ADMIN-CONNECTIONS", "CAP-HR-WEBHOOK-DELIVERY"],
        "classification": "OBLIVION_SPECIFIC_GAP",
        "project": "frappe/frappe",
        "stronger_side": None,
        "result": "Frappe proves validation, signing, dispatch, logging, and retry but not internal-URL safety. Oblivion has a strong HR destination policy, while API settings appear to store/test administrator URLs without invoking it.",
        "anchors": ["app/Http/Controllers/Settings/ApiSettingsController.php:132-211", "app/Http/Controllers/Settings/ApiSettingsController.php:321-360", "app/Domain/Hr/Services/HrWebhookDestinationPolicy.php:59-170", "app/Domain/Hr/Jobs/DeliverHrWebhookJob.php:159-230"],
    },
    {
        "domain": "finance_manual_allocation",
        "candidate_ids": ["CAP-FIN-AR-QUOTE-INVOICE-BILLING", "CAP-FIN-ALLOCATION-MATCH-HISTORY"],
        "classification": "STRONGER_NATIVE_CONTROL",
        "project": "bigcapitalhq/bigcapital",
        "stronger_side": "Oblivion Findings",
        "result": "BigCapital provides adjacent bank-matching locks and atomic state changes. Oblivion's exact manual receipt path adds keyed replay binding, invoice/replay locking, journal posting, append-only allocation evidence, and conflict rejection; historical direct credit is not inherited.",
        "anchors": ["app/Domain/Finance/Services/AccountsReceivableService.php:292-472", "app/Domain/Finance/Services/PaymentSettlementRecorder.php:26-114", "app/Domain/Finance/Models/FinPaymentAllocation.php:47-54"],
    },
]

COMPARATOR_METADATA = {
    "controlled_medications": {
        "allowed_benchmark_outcome": "No credible match",
        "neutral_requirement": "Bind the authoritative controlled-medication order and stock identity; authorize exact action and approved Site; preserve a signed running balance, independent witness, discrepancy handling, and atomic stock/register consequences.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-902-wave21.json@9474a59f10a3aad16ec1dfaeb7e976b9e5f7386a5655c564834e197e092cd2fb"],
    },
    "governance_resolution_vote_quorum": {
        "allowed_benchmark_outcome": "No credible match",
        "neutral_requirement": "Lock the decision aggregate, freeze eligible electorate and quorum evidence, bind conflicts and votes to that snapshot, and close exactly once with reproducible evidence.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-902-wave24.json@c96e62aae6964ee6f1fe8633b6ec07c553dccd42cf1ed352544ca7b234f47c38"],
    },
    "governance_board_packs": {
        "allowed_benchmark_outcome": "No credible match",
        "neutral_requirement": "Create an immutable pack manifest with approval provenance, explicit recipient distribution, read acknowledgement, version history, and safe reproducible regeneration.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-902-wave24.json@c96e62aae6964ee6f1fe8633b6ec07c553dccd42cf1ed352544ca7b234f47c38"],
    },
    "health_safety_registers": {
        "allowed_benchmark_outcome": "No credible match",
        "neutral_requirement": "Require the exact register action plus approved-Site scope and canonical ownership on lists, counts, direct objects, pickers, exports, evidence, and writes.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-902-wave30.json@5fd6e15f7796915c1d4ca2b97cecdc77d0732d030ea4b3c0fc4b1fd78cbc23a7"],
    },
    "privacy_compliance_reports": {
        "allowed_benchmark_outcome": "Native benchmark",
        "neutral_requirement": "Gate each privacy report domain with its exact action authority and accessible-object scope, preserve minimum-necessary fields, sanitize exports, and retain export evidence.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-902-wave17.json@07860807a51ce1e52c59c3dc520671a89672c0bdca2b95948a6fc13f8fdf5c7a"],
    },
    "safeguarding_intake_projections": {
        "allowed_benchmark_outcome": "No credible match",
        "neutral_requirement": "Resolve canonical subject, Site, incident, sensitivity and action authority before intake; project idempotently across owners with durable retry, deduplication, recovery, and terminal reconciliation evidence.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-901-wave2.json@06766d991b6c30afbbdd07729269f3cfcda69b9481a887e21a894aab943b853e", "docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-901-wave3.json@126c81376838c4c814f01ed139ae0c6b6747a290fa3af7046efd26dfd2a828fb"],
    },
    "outbound_webhook_destinations": {
        "allowed_benchmark_outcome": "No credible match",
        "neutral_requirement": "Route every stored, tested, redirected, and delivered outbound destination through one canonical policy that enforces HTTPS, public resolved addresses, DNS/redirect safety, TLS verification, signing, retries, and receipts.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-target-specific-adjudication-902-wave4.json@5b76e440a73edad635bd01d9395dceaeadea2e1642c9b5c165c7be6579afbd84"],
    },
    "finance_manual_allocation": {
        "allowed_benchmark_outcome": "Already better",
        "neutral_requirement": "Bind a stable idempotency key before side effects; lock canonical invoice and replay evidence; reject changed-payload replay; post the journal once; and retain append-only allocation history.",
        "historical_packet_evidence_refs": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-12/evidence/source/benchmark-final-902-mapping.json@21f182c0a2d8cb3416ab7c8d54a27698673e324e0c7945ee9e5fb3a9a61b961f"],
    },
}

for comparator in COMPARATOR_RECORDS:
    project_row = project_index[comparator["project"]]
    comparator.update(COMPARATOR_METADATA[comparator["domain"]])
    comparator["benchmark_project_evidence"] = {
        "canonical_url": project_row["canonical_url"],
        "inspected_ref": project_row["inspected_ref"],
        "commit_sha": project_row["commit_sha"],
        "historical_inspected_date": project_row["inspected_date"],
        "historical_exact_behaviour_locus": project_row["exact_behaviour_screen_workflow_inspected"],
        "current_upstream_refresh_status": "NOT_REFRESHED_2026-08-24_CURRENT_AUDIT",
    }
    comparator["unresolved_evidence"] = "Fresh official-upstream inspection, final candidate identity, full target-specific neutralization, representative role/Site/direct-object runtime behavior, recovery and failure evidence remain open."
    comparator["completion_credit"] = False

assert len(COMPARATOR_RECORDS) == 8
assert Counter(row["classification"] for row in COMPARATOR_RECORDS) == {
    "OBLIVION_SPECIFIC_GAP": 4,
    "NO_CREDIBLE_COMPARISON": 2,
    "STRONGER_NATIVE_CONTROL": 2,
}
assert all(candidate_id in candidate_ids for row in COMPARATOR_RECORDS for candidate_id in row["candidate_ids"])


ASSIGNMENTS = [
    {
        "assignment_id": "RUN-007",
        "agent_task_path": "NOT_RETAINED_IN_STRUCTURED_RETURN; role identity benchmark Observer A retained",
        "role": "benchmark observer",
        "repository": "oblivionfindings workspace; application commit pin controls source identity",
        "application_commit": APPLICATION_COMMIT,
        "architecture_rule": "Single tenant, multiple Sites; evaluate roles/action authority, approved-Site scope, canonical ownership, concealed direct IDs, and privacy—not tenant isolation.",
        "pass_lens": "Pass 3 observer",
        "scope": "Agent-reported historical register structural validation and 30 provisional observed relations; the orchestrator subsequently corrected the prompt denominator",
        "benchmark_subset": "All 98 physical historical rows and 29 current grouped candidates; corrected composition is 95 exact prompt repositories plus three historical extras",
        "evidence_schema": "Pins, structural counts, provisional mappings, limits, evidence count, completion test, runtime gate, and write attestation",
        "no_write_rule": "Return structured evidence in the agent message; do not edit repository files.",
        "completion_test": "Validate every historical row structurally and return bounded provisional observations without upstream or completion inference.",
        "return_status": "COMPLETE_BOUNDED_ASSIGNMENT",
        "evidence_count": 127,
        "evidence_count_basis": "98 historical project rows plus 29 unique current candidate observations.",
        "observed_head": "5bbab8ca2102b72a8fae4320d545860100174e22",
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "unresolved_gaps": "Current upstream activity/ref/licence/edition/behaviour refresh, exact target-specific neutral requirements, and feature completion remain open.",
        "root_reconciliation": "Structural committed-local evidence only; no current upstream truth, licence refresh, current maintenance refresh, selected benchmark, or completion credit. The agent's 97-plus-one prompt composition was rejected: the literal prompt has 98 URL occurrences, 95 unique repositories, and three repeated repositories, while the physical register has those 95 exact repositories plus three historical extras.",
    },
    {
        "assignment_id": "RUN-008",
        "agent_task_path": "/root/benchmark_neutralizer_current",
        "role": "benchmark neutralizer",
        "repository": "oblivionfindings workspace; application commit pin controls source identity",
        "application_commit": APPLICATION_COMMIT,
        "architecture_rule": "Single tenant, multiple Sites; evaluate roles/action authority, approved-Site scope, canonical ownership, concealed direct IDs, and privacy—not tenant isolation.",
        "pass_lens": "Pass 3 neutral writer and collision adversary",
        "scope": "Neutral wording, collision challenge, 15 adjudications, and current coverage-gap accounting",
        "benchmark_subset": "172 current grouped candidates, 904 historical mappings, 98 physical project rows, and 15 exact challenge samples",
        "evidence_schema": "Pins, neutralization rules, adjudications, collision groups, unsupported gaps, register gaps, completion test, runtime gate, and write attestation",
        "no_write_rule": "Return structured evidence in the agent message; do not edit repository files.",
        "completion_test": "Remove labels and generic CRUD similarity, challenge exact identity/owner/locus, report collisions and uncovered rows without inherited credit.",
        "return_status": "COMPLETE_BOUNDED_ASSIGNMENT",
        "evidence_count": 1189,
        "evidence_count_basis": "98 project rows plus 172 current candidates plus 904 historical mapping rows plus 15 exact adjudications.",
        "observed_head": "5bbab8ca2102b72a8fae4320d545860100174e22",
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "unresolved_gaps": "157 effective current rows lack even historical exact credit; grouped identities, named owners, collision splits, and fresh target-specific mappings remain open.",
        "root_reconciliation": "Five fails, five partial requirements, and five narrow static survivals; all remain no-credit because the current denominator is not frozen and target-specific parity is incomplete.",
    },
    {
        "assignment_id": "RUN-009",
        "agent_task_path": "/root/benchmark_native_comparator",
        "role": "native comparator",
        "repository": "oblivionfindings workspace; application commit pin controls source identity",
        "application_commit": APPLICATION_COMMIT,
        "architecture_rule": "Single tenant, multiple Sites; evaluate roles/action authority, approved-Site scope, canonical ownership, concealed direct IDs, and privacy—not tenant isolation.",
        "pass_lens": "Pass 3 native comparator",
        "scope": "Eight high-risk native comparison packets",
        "benchmark_subset": "Controlled medications, governance quorum and board packs, H&S registers, privacy reports, safeguarding projections, outbound destinations, and manual finance allocation",
        "evidence_schema": "Pinned inputs, methods, eight comparison packets, exact native and benchmark anchors, allowed outcome mapping, uncovered domains, evidence count, completion audit, runtime gate, and write attestation",
        "no_write_rule": "Return structured evidence in the agent message; do not edit repository files.",
        "completion_test": "Complete all eight requested target-specific comparison packets and explicitly award no benchmark credit.",
        "return_status": "COMPLETE_AFTER_CORRECTED_FOLLOWUP",
        "evidence_count": 32,
        "evidence_count_basis": "Eight requested comparison packets with four exact local anchor entries each.",
        "observed_head": AUDIT_INPUT_COMMIT,
        "completion_test_met": True,
        "completion_test_correction": "Initial return marked the bounded test false; follow-up supplied all eight requested packets and a replacement completion audit with completion_test_met=true. This is assignment completion only.",
        "wrote_files": False,
        "runtime_gates": None,
        "unresolved_gaps": "All packets remain static and partial; candidate universe, current upstream evidence, runtime behavior, full parity, and final mapping/no-match decisions remain open.",
        "root_reconciliation": "Zero copied-baseline claims, two stronger-native controls, four Oblivion-specific gaps, two no-credible comparisons, and zero benchmark credit.",
    },
]
for assignment in ASSIGNMENTS:
    assignment["normalized_payload_sha256"] = digest(assignment)


BENCHMARK_PAYLOAD = {
    "schema_version": 1,
    "status": "CURRENT_BENCHMARK_WAVE_01_RECONCILED_NO_COMPLETION_CREDIT",
    "generated_at": GENERATED_AT,
    "source": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_input_commit": AUDIT_INPUT_COMMIT,
        "non_audit_product_diff_from_application_commit": 0,
    },
    "pins": {
        "historical_project_register": {
            "sha256": PROJECT_REGISTER_SHA256,
            "physical_unique_rows": 98,
            "exact_prompt_unique_rows": 95,
            "historical_extra_rows": 3,
            "prompt_listed_url_occurrences": 98,
            "prompt_unique_repositories": 95,
        },
        "current_candidate_wave_01_sha256": WAVE_01_SHA256,
        "current_candidate_wave_02_sha256": WAVE_02_SHA256,
        "historical_final_904_sha256": HISTORICAL_904_SHA256,
        "historical_final_902_sha256": HISTORICAL_902_SHA256,
    },
    "project_register_current_audit": {
        "prompt_listed_url_occurrences": 98,
        "prompt_unique_repositories": 95,
        "prompt_repeated_repositories": {
            "glpi-project/glpi": 2,
            "netbox-community/netbox": 2,
            "opf/openproject": 2,
        },
        "physical_unique_rows": 98,
        "exact_prompt_unique_rows_structurally_validated_local_only": 95,
        "historical_extra_rows_structurally_validated_local_only": 3,
        "current_upstream_unique_repository_refreshes": 0,
        "current_upstream_prompt_occurrence_refreshes": 0,
        "current_project_triage_completion_credit": 0,
        "licence_noassertion_rows": 11,
        "prompt_unique_repository_historical_outcomes": {"native_benchmark": 71, "reject": 14, "separate_future_decision": 10, "pending": 0},
        "prompt_occurrence_weighted_historical_outcomes": {"native_benchmark": 74, "reject": 14, "separate_future_decision": 10, "pending": 0},
        "historical_extra_outcomes": {"native_benchmark": 2, "reject": 1},
        "physical_row_outcomes": {"native_benchmark": 73, "reject": 15, "separate_future_decision": 10, "pending": 0},
        "evidence_limit": "The 95 exact prompt repositories and three historical extras are committed-local provenance. The 98 prompt URL occurrences include three duplicate repository listings. Current activity, refs, licences, edition boundaries, and exact upstream behavior were not refreshed on 2026-08-24.",
    },
    "prompt_project_denominator_reconciliation": {
        "status": "CORRECTED_NO_COMPLETION_CREDIT",
        "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
        "prompt_line_range": "496-515",
        "reproduction": "Extract GitHub owner/repository URLs, lowercase and trim trailing slashes; sort with duplicates retained for the occurrence hash; also count unique URLs.",
        "prompt_url_occurrence_multiset_sha256": PROMPT_URL_OCCURRENCE_MULTISET_SHA256,
        "listed_url_occurrences": 98,
        "unique_repositories": 95,
        "duplicate_repository_occurrences": {project: 2 for project in sorted(PROMPT_DUPLICATE_PROJECTS)},
        "physical_register_unique_rows": 98,
        "exact_prompt_unique_rows": 95,
        "historical_extra_rows": [
            {"project": project, "classification": classification}
            for project, classification in sorted(HISTORICAL_EXTRA_PROJECTS.items())
        ],
        "superseded_claim": "97 prompt projects plus one supplemental",
        "consequence": "The superseded composition is not used for current project-triage percentages. Upstream refresh is measured as unique prompt repositories and prompt URL occurrences separately.",
    },
    "observer": {
        "assignment_id": "RUN-007",
        "mapping_records": len(observer_records),
        "unique_current_candidates": len({row["candidate_id"] for row in observer_records}),
        "strength_counts": dict(Counter(row["observer_strength"] for row in observer_records)),
        "mappings": observer_records,
        "completion_credit": False,
    },
    "neutralizer": {
        "assignment_id": "RUN-008",
        "neutralization_rules": [
            "Shared nouns, CRUD labels, dashboard/list/register/report wording, and visual similarity do not establish feature identity.",
            "A narrow comparison needs the exact neutral action/invariant, immutable project ref and locus, and a concrete Oblivion owner.",
            "No sibling/module inheritance; read lenses, mutation owners, external synchronization, exports, and terminal transitions remain separate.",
            "Composite candidates must be split before one comparator can establish identity.",
            "Historical benchmark credit is non-transferable to the current partial candidate universe.",
        ],
        "adjudications": neutralizer_records,
        "result_counts": dict(Counter(row["neutralized_result"] for row in neutralizer_records)),
        "collision_groups": [{"group_id": group_id, "candidate_ids": ids} for group_id, ids in COLLISION_GROUPS],
        "coverage_gaps": {
            "current_candidate_rows": 172,
            "fresh_target_specific_benchmark_or_bounded_no_match_credit": 0,
            "historical_904_exact_id_intersection": 25,
            "historical_exact_credit_rows_not_inherited": 15,
            "historical_exact_uncredited_rows": 10,
            "historical_absent_ids": 147,
            "effective_current_rows_without_historical_credit": 157,
            "candidates_without_class_like_named_owner_heuristic": 54,
            "named_owner_candidates_missing_at_least_one_named_owner_from_production_anchors_heuristic": 48,
        },
        "completion_credit": False,
    },
    "native_comparator": {
        "assignment_id": "RUN-009",
        "comparison_packets": COMPARATOR_RECORDS,
        "classification_counts": dict(Counter(row["classification"] for row in COMPARATOR_RECORDS)),
        "copied_baseline_count": 0,
        "benchmark_credit_awarded": False,
    },
    "current_feature_gate": {
        "partial_grouped_candidates": 172,
        "frozen_canonical_denominator": None,
        "verified_benchmark_or_documented_no_credible_match": 0,
        "completion_percentage": None,
        "reason": "The denominator is not frozen; observer relations, neutralizer challenges, and comparator packets are evidence slices, not final per-feature mappings.",
    },
    "evidence_count": 1348,
    "evidence_count_basis": "Agent-reported evidence counts summed across RUN-007 (127), RUN-008 (1189), and RUN-009 (32); overlap was not deduplicated.",
    "runtime_gates": None,
    "completion_credit": False,
}


AGENT_PAYLOAD = {
    "schema_version": 1,
    "status": "FORMAL_BENCHMARK_WAVE_01_RECONCILED_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "application_commit": APPLICATION_COMMIT,
    "writer_boundary": "Only the root orchestrator wrote audit artifacts; RUN-007 through RUN-009 returned evidence in messages and reported wrote_files=false.",
    "wave_formal_assignments_eligible": 3,
    "cumulative_formal_assignments_eligible": 9,
    "literal_prompt_minimum": 8,
    "literal_prompt_minimum_met": True,
    "planned_formal_assignments_target": 11,
    "planned_target_met": False,
    "all_returned": True,
    "all_completion_tests_met": True,
    "all_reported_no_writes": True,
    "outstanding_required_roles_or_waves": ["RUN-010 page/support adjudication reconciliation", "additional formal assignment(s) toward planned 11", "fresh Pass 8 cross-reviewers", "final no-live-agent reconciliation"],
    "contradictions_and_reconciliation": ["RUN-009 initially returned completion_test_met=false; a bounded follow-up returned all requested packets and a replacement completion audit with assignment-only completion_test_met=true.", "Historical verified benchmark labels and RUN-007 observer strength labels are not promoted into current candidate credit.", "RUN-007 and the first orchestration pass described the register as 97 prompt projects plus one supplemental. Literal prompt reconciliation supersedes that claim with 98 URL occurrences, 95 unique prompt repositories, three repeated repositories, and three historical-extra register rows."],
    "live_agent_finalization_state": "NOT_EVALUATED_FOR_FINALIZATION; audit remains active and fresh Pass 8 has not run.",
    "assignment_returns": ASSIGNMENTS,
    "finalization_gate": False,
}


observer_by_candidate: dict[str, list[dict]] = defaultdict(list)
for record in observer_records:
    observer_by_candidate[record["candidate_id"]].append(record)
neutralizer_by_candidate = {row["candidate_id"]: row for row in neutralizer_records}
comparator_by_candidate: dict[str, list[dict]] = defaultdict(list)
for record in COMPARATOR_RECORDS:
    for candidate_id in record["candidate_ids"]:
        comparator_by_candidate[candidate_id].append(record)

MATRIX_FIELDS = [
    "feature_id", "module", "submodule", "owning_actor", "secondary_actors", "user_job", "criticality", "navigation_entry", "route_names", "route_paths", "page_files", "backend_anchors", "current_states", "current_workflow_summary", "benchmark_candidates", "selected_open_source_benchmark", "benchmark_url_and_sha", "verified_behaviour", "neutral_requirements_extracted", "no_match_evidence", "current_ease_score", "target_ease_score", "P1", "P2", "P3", "P4", "P5", "P6", "P7", "P8", "finding_ids", "confidence",
    "feature_class", "feature_identity_status", "test_anchors", "benchmark_mapping_credit", "completion_status", "evidence_limit",
]

matrix_rows = []
for candidate in candidates:
    candidate_id = candidate["candidate_id"]
    observer = observer_by_candidate.get(candidate_id, [])
    neutral = neutralizer_by_candidate.get(candidate_id)
    comparator = comparator_by_candidate.get(candidate_id, [])
    project_names = []
    for value in [*(row["project"] for row in observer), *(([neutral["project"]] if neutral else [])), *(row["project"] for row in comparator)]:
        for project in str(value).split("; "):
            if project and project not in project_names:
                project_names.append(project)
    project_refs = []
    for project in project_names:
        row = project_index.get(project)
        if row:
            project_refs.append(f"{row['canonical_url']}@{row['commit_sha']}")
        else:
            project_refs.append(f"{project}@HISTORICAL_PACKET_REF_ONLY")
    p3_parts = []
    if observer:
        p3_parts.append("RUN-007_PROVISIONAL_OBSERVER_RELATION_NO_CREDIT")
    if neutral:
        p3_parts.append(f"RUN-008_{neutral['neutralized_result']}_NO_CREDIT")
    if comparator:
        p3_parts.extend(f"RUN-009_{row['classification']}_PACKET_NO_CREDIT" for row in comparator)
    if not p3_parts:
        p3_parts.append("NOT_STARTED_CURRENT_AUDIT")
    matrix_rows.append(
        {
            "feature_id": candidate_id,
            "module": candidate["module"],
            "submodule": "GROUPED_DISCOVERY_CANDIDATE_NOT_FINAL_DENOMINATOR",
            "feature_class": candidate["feature_class"],
            "feature_identity_status": candidate["adjudication_status"],
            "owning_actor": candidate["canonical_owner"],
            "secondary_actors": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "user_job": candidate["user_job"],
            "criticality": "NOT_ADJUDICATED_CURRENT_AUDIT",
            "navigation_entry": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "route_names": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "route_paths": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "page_files": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "backend_anchors": "; ".join(candidate["production_anchors"]),
            "test_anchors": "; ".join(candidate["representative_test_anchors"]),
            "current_states": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "current_workflow_summary": f"Static grouped user job only: {candidate['user_job']}. Representative-role completion was not executed.",
            "benchmark_candidates": "; ".join(project_names) if project_names else "NOT_ESTABLISHED_CURRENT_AUDIT",
            "selected_open_source_benchmark": "NOT_SELECTED_CURRENT_AUDIT",
            "benchmark_url_and_sha": "; ".join(project_refs) if project_refs else "NOT_ESTABLISHED_CURRENT_AUDIT",
            "verified_behaviour": "PROVISIONAL_AGENT_EVIDENCE_ONLY; see evidence/benchmark/current-benchmark-wave-01.json" if project_names else "NOT_ESTABLISHED_CURRENT_AUDIT",
            "neutral_requirements_extracted": neutral["reason"] if neutral else "NOT_ESTABLISHED_CURRENT_AUDIT",
            "no_match_evidence": "COMPARATOR_PACKET_NO_CREDIBLE_PROJECT_FOR_DOMAIN_NOT_FINAL_FEATURE_NO_MATCH" if any(row["classification"] == "NO_CREDIBLE_COMPARISON" for row in comparator) else "NOT_DOCUMENTED_CURRENT_AUDIT",
            "current_ease_score": "NOT_SCORED_CURRENT_AUDIT",
            "target_ease_score": "NOT_SCORED_CURRENT_AUDIT",
            "P1": "PARTIAL_STATIC_GROUPED_DISCOVERY_IDENTITY_NOT_FROZEN",
            "P2": "NOT_STARTED_CURRENT_AUDIT",
            "P3": "; ".join(p3_parts),
            "P4": "NOT_STARTED_CURRENT_AUDIT",
            "P5": "NOT_STARTED_CURRENT_AUDIT",
            "P6": "NOT_STARTED_CURRENT_AUDIT",
            "P7": "NOT_STARTED_CURRENT_AUDIT",
            "P8": "NOT_STARTED_CURRENT_AUDIT",
            "finding_ids": "NOT_LINKED_TO_CANDIDATE_CURRENT_AUDIT",
            "confidence": "LOW_PARTIAL_STATIC_DISCOVERY",
            "benchmark_mapping_credit": "false",
            "completion_status": "INCOMPLETE_GROUPED_DISCOVERY_CANDIDATE",
            "evidence_limit": candidate["evidence_limit"],
        }
    )


CURRENT_REGISTER_FIELDS = historical_fields + [
    "current_audit_prompt_denominator_membership",
    "current_prompt_occurrence_count",
    "current_local_structural_validation",
    "current_upstream_refresh_status",
    "current_target_specific_mapping_credit",
    "current_evidence_limit",
]
current_register_rows = []
for row in historical_projects:
    current = dict(row)
    if row["project"] in HISTORICAL_EXTRA_PROJECTS:
        membership = HISTORICAL_EXTRA_PROJECTS[row["project"]]
        occurrence_count = 0
    else:
        membership = "IN_PROMPT_UNIQUE_95"
        occurrence_count = 2 if row["project"] in PROMPT_DUPLICATE_PROJECTS else 1
    current.update(
        {
            "current_audit_prompt_denominator_membership": membership,
            "current_prompt_occurrence_count": occurrence_count,
            "current_local_structural_validation": "HISTORICAL_ROW_STRUCTURALLY_VALIDATED_COMMITTED_LOCAL_ONLY",
            "current_upstream_refresh_status": "NOT_REFRESHED_2026-08-24_CURRENT_AUDIT",
            "current_target_specific_mapping_credit": "false",
            "current_evidence_limit": "Historical provenance only; current maintenance, ref reachability, licence, edition boundary, behavior and target parity were not reverified upstream.",
        }
    )
    current_register_rows.append(current)


def main() -> None:
    write_json("evidence/benchmark/current-benchmark-wave-01.json", BENCHMARK_PAYLOAD)
    write_json("evidence/benchmark/current-benchmark-agent-register.json", AGENT_PAYLOAD)
    write_json(
        "evidence/benchmark/current-prompt-project-denominator-reconciliation.json",
        {
            "schema_version": 1,
            "status": "PROMPT_PROJECT_DENOMINATOR_RECONCILED_NO_UPSTREAM_OR_COMPLETION_CREDIT",
            "generated_at": GENERATED_AT,
            **BENCHMARK_PAYLOAD["prompt_project_denominator_reconciliation"],
            "credit_boundary": "This corrects the project denominator only. It grants zero current upstream, licence, activity, behaviour, benchmark-selection, feature-mapping, or audit-completion credit.",
        },
    )
    write_csv("03-feature-to-benchmark-matrix.csv", MATRIX_FIELDS, matrix_rows)
    write_csv("06-open-source-benchmark-register.csv", CURRENT_REGISTER_FIELDS, current_register_rows)


if __name__ == "__main__":
    main()
