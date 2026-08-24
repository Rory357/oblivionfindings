#!/usr/bin/env python3
"""Build the fourth target-specific benchmark wave for the 902-target audit.

This audit-only generator records twelve fresh direct material matches and
twelve bounded No Credible Match research candidates. Independent review
accepted the direct rows and kept the NCM rows pending because their searches
do not yet satisfy the audit's broader-search completion rule.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST = SOURCE / "working-capability-manifest-902.json"
BASE_MAPPING = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT = SOURCE / "benchmark-target-specific-adjudication-902-wave4.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST_SHA = "4a90a19d20ffe332840d46dfe3e427d224d0f53db2a4b8515a7b5c64d2f59fb8"
BASE_MAPPING_SHA = "583975fe82f7960375ae5b3bf4f442278d3a3f505734ae9077203e9ac4f66972"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def lines_sha(values: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


def load(path: Path) -> dict:
    with path.open("r", encoding="utf-8-sig") as handle:
        return json.load(handle)


def evidence_locus(repo_url: str, commit_sha: str, source_locus: str) -> str:
    owner_repo = repo_url.removeprefix("https://github.com/")
    path, ranges = source_locus.split(":", 1)
    return f"{owner_repo}@{commit_sha} :: {path} :: {ranges}"


manifest = load(MANIFEST)
assert sha(MANIFEST) == MANIFEST_SHA
assert sha(BASE_MAPPING) == BASE_MAPPING_SHA
assert manifest["audited_commit"] == COMMIT
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
assert len(manifest_by_key) == 902


def lineage(key: str) -> dict:
    target = manifest_by_key[key]
    return {
        "id_status": target["id_status"],
        "class": target["class"],
        "canonical_module": target["canonical_module"],
        "source_family_ids": sorted(target.get("source_family_ids", [])),
        "route_ids": sorted(target.get("route_ids", [])),
        "page_ids": sorted(target.get("page_ids", [])),
        "backend_anchors": sorted(target.get("backend_anchors", [])),
    }


def direct(
    key: str,
    neutral_requirement: str,
    repo_url: str,
    commit_sha: str,
    source_loci: list[str],
    proven_slice: str,
    parity_limits: str,
    spdx: str,
    edition_boundary: str,
) -> dict:
    return {
        "working_key": key,
        "adjudication_id": f"fresh-902-wave4:{key}",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": neutral_requirement,
        "search_terms": [
            f"{key} analogous persisted open source behavior",
            neutral_requirement,
        ],
        "current_source_lineage": lineage(key),
        "evidence_loci": [evidence_locus(repo_url, commit_sha, item) for item in source_loci],
        "benchmark": {
            "official_repository_url": repo_url,
            "commit_sha": commit_sha,
            "source_loci": source_loci,
            "proven_slice": proven_slice,
            "parity_limits": parity_limits,
            "licence": {"spdx": spdx, "edition_boundary": edition_boundary},
        },
        "inheritance_boundary": "Fresh target-specific material slice; no source-family, sibling, split, merge, rename or module-wide outcome is inherited.",
    }


def rejected(
    repo_url: str,
    commit_sha: str,
    source_loci: list[str],
    reason: str,
    spdx: str,
    edition_boundary: str,
) -> dict:
    return {
        "official_repository_url": repo_url,
        "commit_sha": commit_sha,
        "source_loci": source_loci,
        "reason": reason,
        "licence": {"spdx": spdx, "edition_boundary": edition_boundary},
    }


def ncm(
    key: str,
    neutral_requirement: str,
    repositories: list[dict],
    bounded_ncm_reason: str,
) -> dict:
    loci = [
        evidence_locus(item["official_repository_url"], item["commit_sha"], source_locus)
        for item in repositories
        for source_locus in item["source_loci"]
    ]
    return {
        "working_key": key,
        "adjudication_id": f"fresh-902-wave4:{key}",
        "candidate_status": "ncm_research_pending",
        "completion_credit_recommended": False,
        "review_status": "insufficient_beyond_seed_search_for_completion_credit",
        "review_blockers": [
            "The stored search terms do not yet demonstrate the full target-specific search corpus considered.",
            "The three selected repositories do not yet demonstrate the required credible beyond-seed search.",
        ],
        "neutral_requirement": neutral_requirement,
        "search_terms": [
            f"{key} same actor state completion open source",
            neutral_requirement,
        ],
        "current_source_lineage": lineage(key),
        "evidence_loci": loci,
        "rejected_repositories": repositories,
        "bounded_ncm_reason": bounded_ncm_reason,
        "inheritance_boundary": "Fresh target-specific bounded corpus result; no source-family, sibling, split, merge, rename or prior NCM is inherited.",
    }


KEYCLOAK = "https://github.com/keycloak/keycloak"
KEYCLOAK_SHA = "b515f41a5c56936a4aa9a86e70f8479359aeccc4"
OPENEMR = "https://github.com/openemr/openemr"
OPENEMR_SHA = "a6321a3e2978e62472dbb6c721b680ca2d65b592"
ERPNEXT = "https://github.com/frappe/erpnext"
ERPNEXT_SHA = "c6d08979d3ffab119d1523ee2bdea2c8115a8bca"
OPENPROJECT = "https://github.com/opf/openproject"
OPENPROJECT_SHA = "6717bde12ef269daac04726114c173f2b66b72d5"
HRMS = "https://github.com/frappe/hrms"
HRMS_SHA = "4f6ee8504003cf2ea36488cd4876b8b90a8bf68c"
FRAPPE = "https://github.com/frappe/frappe"
FRAPPE_SHA = "e97865c3212334a6b3112177ee0aa81620173f81"
GROCY = "https://github.com/grocy/grocy"
GROCY_SHA = "c4e7acda48c1f27a163ffaf66c14cca876675622"


evaluations = [
    direct(
        "CAP-AUTH-STAFF-EXTERNAL-IDENTITY",
        "Support staff sign in through configured external identity providers, including redirect, callback processing, account linking, and authenticated disconnection of a linked provider.",
        KEYCLOAK,
        KEYCLOAK_SHA,
        [
            "services/src/main/java/org/keycloak/services/resources/IdentityBrokerService.java:L394-L438",
            "services/src/main/java/org/keycloak/services/resources/IdentityBrokerService.java:L464-L475",
            "services/src/main/java/org/keycloak/services/resources/IdentityBrokerService.java:L699-L741",
            "services/src/main/java/org/keycloak/services/resources/account/LinkedAccountsResource.java:L291-L330",
        ],
        "Provider-alias login redirects, provider callback dispatch, authenticated broker-context processing, and permission-checked removal of a linked identity are directly implemented.",
        "Provider-generic behavior does not prove Google/Microsoft configuration, Oblivion staff-role and site-access mapping, callback UI, or identical disconnect preconditions.",
        "Apache-2.0",
        "Only the upstream community repository is credited; hosted and vendor-specific commercial integration is excluded.",
    ),
    direct(
        "CAP-AUTH-TWO-FACTOR-LOGIN-CHALLENGE",
        "Present a second-factor login challenge, validate an authenticator code, reject invalid codes, and complete authentication only after valid second-factor proof.",
        KEYCLOAK,
        KEYCLOAK_SHA,
        [
            "services/src/main/java/org/keycloak/authentication/authenticators/browser/OTPFormAuthenticator.java:L61-L120",
            "services/src/main/java/org/keycloak/authentication/authenticators/browser/OTPFormAuthenticator.java:L142-L155",
        ],
        "The authenticator renders a TOTP challenge, reads the selected credential and OTP, checks user and brute-force state, rejects invalid credentials, and succeeds only on valid OTP.",
        "Recovery-code entry, Laravel Fortify route/session policy, rate limits, local UI and enrolment/recovery lifecycle are outside this slice.",
        "Apache-2.0",
        "Only the OTP authenticator in the upstream community repository is credited.",
    ),
    direct(
        "CAP-CLIN-OBSERVATION-REGISTER-RECORD",
        "Provide an authorised clinical observation register and record an observation against the active person and encounter with validation and persisted clinical context.",
        OPENEMR,
        OPENEMR_SHA,
        [
            "src/Controllers/Interface/Forms/Observation/ObservationController.php:L75-L139",
            "src/Controllers/Interface/Forms/Observation/ObservationController.php:L180-L220",
            "src/Controllers/Interface/Forms/Observation/ObservationController.php:L242-L354",
        ],
        "OpenEMR checks observation-form permission, lists and loads patient/encounter observations, validates submitted observations, binds patient/encounter/user context, and saves transactionally.",
        "The local taxonomy, dashboard, filters, dialog, site access and direct-object denial are not proved; no related clinical target inherits credit.",
        "GPL-3.0",
        "Only the GPL observation controller is credited; hosted services and third-party modules are excluded.",
    ),
    direct(
        "CAP-FIN-PURCHASE-ORDER-LIFECYCLE",
        "Create, validate, review, submit or approve, cancel, close, reopen, and track purchase orders through explicit lifecycle states.",
        ERPNEXT,
        ERPNEXT_SHA,
        [
            "erpnext/buying/doctype/purchase_order/purchase_order.py:L195-L205",
            "erpnext/buying/doctype/purchase_order/purchase_order.py:L402-L468",
            "erpnext/buying/doctype/purchase_order/purchase_order.py:L585-L602",
            "erpnext/buying/doctype/purchase_order/purchase_order.json:L890-L902",
        ],
        "ERPNext implements purchase-order validation, submit/cancel hooks, approval-authority validation, permission-checked close/reopen operations and concrete states.",
        "Submission/approval semantics, local fields, tax treatment, status vocabulary and page composition differ; bill conversion is excluded.",
        "GPL-3.0",
        "Only the GPL ERPNext repository is credited; hosted services and private integrations are excluded.",
    ),
    direct(
        "CAP-FIN-QUOTE-LIFECYCLE",
        "Create and maintain quotations, validate validity periods, track progression, submit or cancel, and record ordered, lost, or expired outcomes.",
        ERPNEXT,
        ERPNEXT_SHA,
        [
            "erpnext/selling/doctype/quotation/quotation.py:L143-L165",
            "erpnext/selling/doctype/quotation/quotation.py:L188-L204",
            "erpnext/selling/doctype/quotation/quotation.py:L266-L316",
            "erpnext/selling/doctype/quotation/quotation.py:L376-L404",
            "erpnext/selling/doctype/quotation/quotation.json:L844-L855",
        ],
        "ERPNext implements quotation validation, validity dates, open/partially ordered/ordered computation, lost reasons, submit/cancel hooks, expiry and explicit states.",
        "State names, local send/accept endpoints, client selection, pricing and delivery are not equivalent; conversions are excluded.",
        "GPL-3.0",
        "Only the GPL ERPNext repository is credited; hosted services and private extensions are excluded.",
    ),
    direct(
        "CAP-FIN-ACCOUNTS-RECEIVABLE-AGING",
        "Produce an accounts-receivable ageing view that calculates outstanding receivables as of a report date and allocates them into configurable age buckets.",
        ERPNEXT,
        ERPNEXT_SHA,
        [
            "erpnext/accounts/report/accounts_receivable/accounts_receivable.py:L41-L80",
            "erpnext/accounts/report/accounts_receivable/accounts_receivable.py:L802-L836",
            "erpnext/accounts/report/accounts_receivable/accounts_receivable.js:L94-L105",
        ],
        "The report is configured for receivables, supports report-date or current-date ageing, configurable 30/60/90/120 ranges, and assigns outstanding amounts to the matching bucket.",
        "Local columns, summaries, filters, accounting basis, currency and visual presentation are not proved; no other receivables target inherits credit.",
        "GPL-3.0",
        "Only the checked GPL report implementation is credited.",
    ),
    direct(
        "CAP-GOV-MEETING-SCHEDULING-AGENDA",
        "Schedule meetings with title, time, duration, location, participants and state, and create, order, edit or remove structured agenda items with presenter, notes and duration.",
        OPENPROJECT,
        OPENPROJECT_SHA,
        [
            "docs/api/apiv3/components/schemas/meeting_write_model.yml:L3-L34",
            "docs/api/apiv3/components/schemas/meeting_agenda_item_write_model.yml:L3-L47",
            "modules/meeting/app/models/meeting.rb:L31-L54",
            "modules/meeting/app/models/meeting.rb:L69-L95",
            "modules/meeting/app/models/meeting.rb:L131-L143",
            "modules/meeting/app/models/meeting_agenda_item.rb:L32-L89",
            "modules/meeting/app/components/meeting_agenda_items/form_component.html.erb:L1-L35",
        ],
        "OpenProject models scheduled meeting fields/states, participants, visibility, upcoming/past scopes and ordered agenda items with title, duration, notes and presenter.",
        "Project meetings do not prove board roles, local categories, minutes approval, locking, RSVP, resolutions or board packs.",
        "GPL-3.0",
        "The cited meeting module is in the GPL Community repository; Enterprise add-ons are excluded.",
    ),
    direct(
        "CAP-HR-EXPENSE-AUTHOR-SUBMIT",
        "Allow an employee to author an expense claim with line items and attachments, validate it, and submit it into an approval lifecycle.",
        HRMS,
        HRMS_SHA,
        [
            "frontend/src/views/expense_claim/Form.vue:L4-L16",
            "frontend/src/views/expense_claim/Form.vue:L86-L99",
            "hrms/hr/doctype/expense_claim/expense_claim.py:L105-L174",
            "hrms/hr/doctype/expense_claim/expense_claim.py:L196-L223",
        ],
        "The employee form defaults to the current employee, supports attachments and submission; backend code validates employee/amounts, totals and taxes, prevents disallowed self-approval, and applies submit/cancel effects.",
        "Local draft submission, receipts, on-behalf authoring, categories and approval roles are not proved; review and payment are excluded.",
        "GPL-3.0",
        "Only the GPL HRMS repository is credited; hosted services and ERPNext integration are outside the slice.",
    ),
    direct(
        "CAP-HR-PAYSLIP-SELF",
        "Provide employee self-service access to submitted payslips, detailed earnings and deductions, and a downloadable payslip document.",
        HRMS,
        HRMS_SHA,
        [
            "frontend/src/views/salary_slip/Dashboard.vue:L35-L50",
            "frontend/src/views/salary_slip/Dashboard.vue:L97-L113",
            "frontend/src/views/salary_slip/Detail.vue:L4-L40",
            "frontend/src/views/salary_slip/Detail.vue:L136-L157",
            "hrms/payroll/doctype/salary_slip/salary_slip.json:L771-L800",
        ],
        "The dashboard filters submitted Salary Slips to the active employee, links detail views, exposes earnings/deductions/net pay and PDF download, with Employee read/print rights.",
        "Client filtering and role metadata alone do not prove Oblivion direct-object denial, private-file authorization, fields, tax treatment or presentation; administration is excluded.",
        "GPL-3.0",
        "Only the GPL self-service frontend and repository permission metadata are credited.",
    ),
    direct(
        "CAP-SET-ROLE-ASSIGNMENTS",
        "Allow an authorised administrator to inspect, add, remove, and persist user role assignments while preventing unauthorised role editing.",
        FRAPPE,
        FRAPPE_SHA,
        [
            "frappe/core/doctype/user/user.js:L74-L100",
            "frappe/core/doctype/user/user.js:L316-L319",
            "frappe/core/doctype/user/user.js:L478-L488",
            "frappe/core/doctype/user/user.py:L718-L734",
            "frappe/core/doctype/user/user.json:L250-L257",
        ],
        "Frappe renders the role editor only with qualifying write permission, persists role tables, and provides direct add-role and remove-role methods.",
        "Local approvals, board membership, overrides and primary-role compatibility are not proved; definitions and permission matrices are excluded.",
        "MIT",
        "Only the MIT framework repository is credited; Frappe Cloud and application-specific extensions are excluded.",
    ),
    direct(
        "CAP-SET-OUTBOUND-WEBHOOK-CONFIG",
        "Configure an enabled outbound webhook with event trigger, condition, destination, method, payload/headers, secret, timeout and retry behavior, then dispatch and log deliveries.",
        FRAPPE,
        FRAPPE_SHA,
        [
            "frappe/integrations/doctype/webhook/webhook.json:L8-L34",
            "frappe/integrations/doctype/webhook/webhook.json:L42-L72",
            "frappe/integrations/doctype/webhook/webhook.json:L84-L101",
            "frappe/integrations/doctype/webhook/webhook.json:L154-L165",
            "frappe/integrations/doctype/webhook/webhook.py:L67-L106",
            "frappe/integrations/doctype/webhook/webhook.py:L155-L213",
            "frappe/integrations/doctype/webhook/webhook.py:L339-L359",
        ],
        "Frappe defines, validates, signs and dispatches configurable webhooks, records delivery status, and schedules retries on failure.",
        "Local event catalogue, record shape, test endpoint, manage permission, secret exposure, internal-URL safety and privacy remain unproved.",
        "MIT",
        "Only the MIT framework webhook DocType and dispatcher are credited; hosted integrations are excluded.",
    ),
    direct(
        "CAP-SITE-MEAL-INVENTORY-ITEM-REGISTER",
        "Maintain a meal-stock item register with add, view, edit, disable or delete operations and item attributes including name, location, units and minimum stock.",
        GROCY,
        GROCY_SHA,
        [
            "routes.php:L60-L68",
            "routes.php:L164-L171",
            "controllers/StockController.php:L284-L314",
            "views/products.blade.php:L25-L39",
            "views/products.blade.php:L110-L205",
            "views/productform.blade.php:L71-L132",
            "views/productform.blade.php:L192-L201",
            "views/productform.blade.php:L675-L684",
            "controllers/Api/GenericEntityApiController.php:L19-L65",
            "controllers/Api/GenericEntityApiController.php:L78-L120",
            "controllers/Api/GenericEntityApiController.php:L128-L181",
        ],
        "Grocy provides a product register, create/edit forms, list filtering, name/location/units/minimum stock/active fields and permission-checked create/update/delete operations.",
        "Household inventory does not prove Oblivion site scope, ownership, meal-product links, movements, stocktakes or permissions; related meal jobs are excluded.",
        "MIT",
        "Only the self-hosted MIT repository is credited; external barcode providers and services are excluded.",
    ),
]


NCM_OPENEMR = "https://github.com/openemr/openemr"
NCM_OPENEMR_SHA = "312b5d042f7fa49cc78ebed041155fba339a991c"
BAHMNI_ADMIN = "https://github.com/Bahmni/openmrs-module-medicationadministration"
BAHMNI_ADMIN_SHA = "acb65f75d3515b4f2b36083345bfbafc2ee146b0"
BAHMNI_IPD = "https://github.com/Bahmni/openmrs-module-ipd-frontend"
BAHMNI_IPD_SHA = "cd5aff98be8b6b46cf85ac4e61964c5f27eb59d9"
TRACCAR = "https://github.com/traccar/traccar"
TRACCAR_SHA = "17e7a330e8a07896f000898b37dc770f2df3c142"
THINGSBOARD = "https://github.com/thingsboard/thingsboard"
THINGSBOARD_SHA = "684f92bbfd0cf48015b6e42f5592bc0c2fc18038"
CARE = "https://github.com/ohcnetwork/care"
CARE_SHA = "c64a1ef0e726b06788b63bf4537dea5d16af2269"
OCA_FLEET = "https://github.com/OCA/fleet"
OCA_FLEET_SHA = "08953b7fbd10d766f5c816cadd98f1000fcf995c"
BEACON = "https://github.com/braedonsaunders/beaconhs"
BEACON_SHA = "7de18df3532a96883e1098dea0a4b4dba70909fd"
NCM_OPENPROJECT = "https://github.com/opf/openproject"
NCM_OPENPROJECT_SHA = "0d46ea5d912e32f877537c59d8ab016b0c3e168f"
CISO = "https://github.com/intuitem/ciso-assistant-community"
CISO_SHA = "1ba187b0117f4dba9e00605bd7d5319ded61cee3"
ONEUPTIME = "https://github.com/OneUptime/oneuptime"
ONEUPTIME_SHA = "ba44f303182bb8e896809c8491023c8d2210f4ee"


med_admin = rejected(
    BAHMNI_ADMIN,
    BAHMNI_ADMIN_SHA,
    ["api/src/main/java/org/openmrs/module/ipd/api/model/MedicationAdministration.java:L27-L59"],
    "An administration record does not establish the selected target-specific governance or completion lifecycle.",
    "MPL-2.0",
    "Only the pinned native medication-administration module is considered.",
)
med_tasks = rejected(
    BAHMNI_IPD,
    BAHMNI_IPD_SHA,
    ["src/features/DisplayControls/NursingTasks/utils/NursingTasksUtils.js:L49-L213"],
    "Medication task presentation and terminal-state derivation do not establish the selected persisted target lifecycle.",
    "MPL-2.0",
    "Only the pinned native frontend source is considered; UI state is not persistence proof.",
)


evaluations.extend([
    ncm(
        "CAP-MED-BREAK-GLASS-POLICY",
        "Persist an emergency-access policy with default, maximum and extension durations, reason requirement and repeat-use thresholds, validating default duration against the maximum.",
        [
            rejected(NCM_OPENEMR, NCM_OPENEMR_SHA, ["src/Common/Logging/BreakglassChecker.php:L3-L62"], "Emergency-group membership has no editable durations, reason requirement, repeat thresholds or cross-field policy validation.", "GPL-3.0", "Only repository-native GPL source is considered."),
            med_admin,
            med_tasks,
        ],
        "The three pinned healthcare repositories provide emergency-group membership and ordinary medication records/tasks, but no configurable persisted emergency-access policy with the selected validation boundary.",
    ),
    ncm(
        "CAP-MED-CD-LOSS-INVESTIGATION-CLOSURE",
        "Move a controlled-drug loss report into investigation with notes, then resolve it with outcome, resolver/time evidence and linked medication-incident closure.",
        [
            med_admin,
            med_tasks,
            rejected(NCM_OPENEMR, NCM_OPENEMR_SHA, ["library/classes/Prescription.class.php:L109-L156"], "General prescription state does not establish controlled-drug loss intake, investigation, resolution or linked-incident completion.", "GPL-3.0", "Only repository-native GPL source is considered."),
        ],
        "The pinned medication corpus contains administration, task and prescription records but no controlled-drug loss investigation-to-resolution workflow with linked incident closure.",
    ),
    ncm(
        "CAP-MED-COVERT-AUTHORISATION",
        "Activate and revoke a medication-specific covert-administration authorisation with named authoriser, clinical/legal basis, method, pharmacist advice and review evidence.",
        [
            med_admin,
            med_tasks,
            rejected(NCM_OPENEMR, NCM_OPENEMR_SHA, ["library/classes/Prescription.class.php:L109-L156"], "Ordinary prescription fields do not establish a covert-administration legal authorisation or review/revocation lifecycle.", "GPL-3.0", "Only repository-native GPL source is considered."),
        ],
        "Ordinary prescriptions and administrations exist, but none of the pinned repositories reaches the selected legal/clinical covert-authorisation object and active-to-revoked lifecycle.",
    ),
    ncm(
        "CAP-MED-SYRINGE-DRIVER-MONITORING",
        "Commence a witnessed syringe-driver run, append timed running/site/volume checks, and complete or stop only after required monitoring evidence.",
        [
            med_admin,
            med_tasks,
            rejected(NCM_OPENEMR, NCM_OPENEMR_SHA, ["src/FHIR/R4/FHIRDomainResource/FHIRMedicationAdministration.php:L74-L184"], "A FHIR resource schema with rate/site fields is not a persisted device-run, repeated-check, witness and guarded-completion journey.", "GPL-3.0", "Only repository-native GPL source is considered; resource structure is not workflow proof."),
        ],
        "The inspected repositories expose medication-administration fields and task states, but no device-run, witness, repeated-check and guarded terminal boundary.",
    ),
    ncm(
        "CAP-FLEET-DEVICE-TRACKING-CONSENT",
        "Bind versioned location-tracking consent to a supported person and device assignment, record grant/expiry/supersession, and revoke with withdrawal evidence that stops consented tracking.",
        [
            rejected(TRACCAR, TRACCAR_SHA, ["src/main/java/org/traccar/api/resource/PositionResource.java:L40-L155"], "Position permission is not a versioned person/device tracking-consent grant and withdrawal record.", "Apache-2.0", "Only repository-native server source is considered."),
            rejected(THINGSBOARD, THINGSBOARD_SHA, ["application/src/main/java/org/thingsboard/server/controller/DeviceController.java:L254-L289"], "Device assignment is not a consent decision and has no consent version, signatory, expiry or withdrawal evidence.", "Apache-2.0", "Only Community repository source is considered; Professional Edition is excluded."),
            rejected(CARE, CARE_SHA, ["care/emr/models/consent.py:L6-L16", "care/emr/models/device.py:L7-L71", "care/emr/api/viewsets/consent.py:L26-L100"], "Encounter consent and device models remain separate; no source links withdrawal to tracking availability.", "MIT", "Only repository-native backend source is considered."),
        ],
        "Position permissions, device assignment and clinical consent exist separately, but none joins them into the selected person/device location-tracking consent and withdrawal boundary.",
    ),
    ncm(
        "CAP-FLEET-RESIDENT-TRANSPORT-LIFECYCLE",
        "Start and complete resident transport against resident, shift, eligible driver and vehicle, preserving pre-check, medicine custody, witness and accounted-for closeout evidence.",
        [
            rejected(CARE, CARE_SHA, ["care/emr/api/viewsets/resource_request.py:L26-L125"], "Patient resource requests lack a physical journey, driver/shift/vehicle checks, medicine custody and completion blockers.", "MIT", "Only repository-native backend source is considered."),
            rejected(OCA_FLEET, OCA_FLEET_SHA, ["fleet_vehicle_inspection/models/fleet_vehicle_inspection.py:L9-L153"], "Vehicle inspection is only a precondition and lacks resident journey, medicine custody, witness and terminal accountability.", "AGPL-3.0", "Only the cited OCA add-on is considered."),
            rejected(TRACCAR, TRACCAR_SHA, ["src/main/java/org/traccar/api/resource/PositionResource.java:L40-L155"], "Position history does not own resident-transport state, shift handoff, medicine custody or terminal accountability.", "Apache-2.0", "Only repository-native server source is considered."),
        ],
        "Request, inspection and position primitives exist, but no pinned repository establishes the combined resident, driver, shift, vehicle, medication-custody and blocked-completion journey.",
    ),
    ncm(
        "CAP-HS-LONE-WORKER-SESSION-CHECKIN",
        "Check in to an own or authorised on-behalf lone-worker session, recording status/location/time and driving emergency alert and terminal-session transitions.",
        [
            rejected(TRACCAR, TRACCAR_SHA, ["src/main/java/org/traccar/api/resource/PositionResource.java:L40-L155", "src/main/java/org/traccar/api/resource/CommandResource.java:L97-L183"], "Positions and commands do not establish a person-owned timed safety session, actor split, check-in sequence or emergency transition.", "Apache-2.0", "Only repository-native server source is considered."),
            rejected(THINGSBOARD, THINGSBOARD_SHA, ["application/src/main/java/org/thingsboard/server/controller/AlarmController.java:L127-L227"], "Alarm handling lacks a periodic lone-worker session, own-worker authorization and check-in-driven emergency state.", "Apache-2.0", "Only Community repository source is considered; Professional Edition is excluded."),
            rejected(BEACON, BEACON_SHA, ["packages/db/src/schema/corrective-actions.ts:L32-L123"], "Corrective-action governance has no live worker-monitoring session, periodic check-ins or panic/emergency boundary.", "AGPL-3.0", "Immature official project used only as a schema comparison."),
        ],
        "Tracking, alarm and safety-action primitives exist, but no repository reaches the selected own-worker/on-behalf check-in, live-session, emergency and terminal boundary.",
    ),
    ncm(
        "CAP-HS-EMERGENCY-DRILL-LIFECYCLE",
        "Schedule, start, staff, roll-call, complete or cancel a site emergency drill with evacuation timing, findings, evidence and terminal outcome.",
        [
            rejected(BEACON, BEACON_SHA, ["packages/db/src/schema/corrective-actions.ts:L32-L123"], "Corrective actions lack drill schedule/scenario, wardens, roll call, evacuation evidence and drill outcome.", "AGPL-3.0", "Immature official project used only as a schema comparison."),
            rejected(NCM_OPENPROJECT, NCM_OPENPROJECT_SHA, ["app/models/work_package.rb:L226-L288"], "Generic work tracking does not establish drill participants, roll call, evacuation measures or drill-specific terminal semantics.", "GPL-3.0", "Only Community repository source is considered; Enterprise modules are excluded."),
            rejected(CISO, CISO_SHA, ["backend/doc_management/models.py:L14-L256"], "Document review and evidence are not drill execution and lack roles, attendance, roll call and evacuation outcome.", "AGPL-3.0 community", "Only Community repository paths are considered; enterprise modules are excluded."),
        ],
        "Corrective actions, work items and governed documents are partial primitives only; none implements the selected drill execution and roll-call completion boundary.",
    ),
    ncm(
        "CAP-RESP-STAY-CLINICAL-RECONCILIATION",
        "Record stay-specific admission/discharge medication reconciliation and linked restraint evidence with discrepancy, override and completion state.",
        [
            rejected(CARE, CARE_SHA, ["care/emr/api/viewsets/form_submission.py:L25-L94", "care/emr/api/viewsets/encounter.py:L54-L209"], "Generic encounter/forms do not establish reconciliation states, discrepancy/override completion or the selected restraint record.", "MIT", "Only repository-native backend source is considered."),
            med_admin,
            med_tasks,
        ],
        "Encounter, form, administration and task primitives exist, but none reaches the combined stay-specific medication-reconciliation and restraint-record boundary.",
    ),
    ncm(
        "CAP-RESP-RISK-PLAN-ACKNOWLEDGEMENT",
        "Show active stay/person risk plans not yet acknowledged by the worker and persist once-only acknowledgement identity, count and audit/event evidence.",
        [
            rejected(CARE, CARE_SHA, ["care/emr/api/viewsets/consent.py:L26-L100"], "Per-user encounter-consent verification is not a stay-linked risk-plan acknowledgement queue or ledger.", "MIT", "Only repository-native backend source is considered."),
            rejected(NCM_OPENPROJECT, NCM_OPENPROJECT_SHA, ["app/models/work_package.rb:L226-L288"], "Assignee/status is not an independent per-worker acknowledgement set with stay/person risk-plan context.", "GPL-3.0", "Only Community repository source is considered."),
            rejected(CISO, CISO_SHA, ["backend/doc_management/models.py:L14-L256"], "Document reviewers do not establish a once-only worker acknowledgement ledger for an active care risk plan.", "AGPL-3.0 community", "Only Community paths are considered; enterprise modules are excluded."),
        ],
        "Consent verification, assignment and document review are close primitives, but none satisfies the stay/person risk-plan, once-only acknowledgement, outstanding queue and audit/event boundary.",
    ),
    ncm(
        "CAP-SEC-QUECLINK-HUB-RESIDENT-SAFETY",
        "Apply a native validated GL30M resident-safety parameter preset to a paired compatible tracker, queue the command and retain preset-application audit evidence.",
        [
            rejected(TRACCAR, TRACCAR_SHA, ["src/main/java/org/traccar/api/resource/CommandResource.java:L97-L183"], "Generic queued commands do not establish a typed GL30M resident-safety bundle, compatibility gate or named preset completion.", "Apache-2.0", "Only repository-native server source is considered."),
            rejected(THINGSBOARD, THINGSBOARD_SHA, ["application/src/main/java/org/thingsboard/server/controller/AbstractRpcController.java:L71-L170"], "Generic RPC lacks the selected GL30M-specific safety preset, parameter validation and compatible-device gate.", "Apache-2.0", "Only Community repository source is considered; Professional Edition is excluded."),
            rejected(CARE, CARE_SHA, ["care/emr/api/viewsets/device.py:L58-L117", "care/emr/models/device.py:L7-L71"], "Clinical device lifecycle has no tracker RPC/configuration, Queclink family or safety preset.", "MIT", "Only repository-native backend source is considered."),
        ],
        "Generic command/RPC and device custody exist, but no repository exposes the target's native validated GL30M safety preset and application-audit boundary.",
    ),
    ncm(
        "CAP-CR-SHIFT-HANDOVER",
        "Complete an outgoing control-room shift with priority handover, atomically start and link an incoming validated team, and require incoming acknowledgement before activation.",
        [
            rejected(ONEUPTIME, ONEUPTIME_SHA, ["App/FeatureSet/Dashboard/src/Components/OnCallPolicy/ExecutionLogs/ExecutionLogsTimelineTable.tsx:L34-L254", "App/FeatureSet/Dashboard/src/Components/Incident/IncidentFeed.tsx:L41-L239"], "Escalation/feed history does not create linked outgoing/incoming shifts, carry priorities or require incoming acknowledgement.", "Apache-2.0", "Plan-annotated paid paths are excluded; only Community-neutral paths are considered."),
            rejected(NCM_OPENPROJECT, NCM_OPENPROJECT_SHA, ["app/models/work_package.rb:L226-L288", "app/models/message.rb:L31-L149"], "Work and messages do not establish an atomic shift transition, incoming team validation, shift linkage or acknowledgement gate.", "GPL-3.0", "Only Community repository source is considered."),
            rejected(THINGSBOARD, THINGSBOARD_SHA, ["application/src/main/java/org/thingsboard/server/controller/AlarmController.java:L127-L227"], "Alarm ownership and acknowledgement do not create linked outgoing/incoming operator shifts or a shift-level handover state.", "Apache-2.0", "Only Community repository source is considered; Professional Edition is excluded."),
        ],
        "Escalation, feed, work, messaging and alarm primitives exist, but none reaches the linked outgoing/incoming shift, team, priority-item and acknowledgement completion boundary.",
    ),
])


keys = [row["working_key"] for row in evaluations]
candidate_keys = sorted(row["working_key"] for row in evaluations if row["candidate_status"] == "candidate_found_direct")
ncm_keys = sorted(row["working_key"] for row in evaluations if row["candidate_status"] == "ncm_research_pending")
assert len(keys) == len(set(keys)) == 24
assert len(candidate_keys) == len(ncm_keys) == 12
assert all(key in manifest_by_key for key in keys)
assert all(row["completion_credit_recommended"] is True for row in evaluations if row["candidate_status"] == "candidate_found_direct")
assert all(row["completion_credit_recommended"] is False for row in evaluations if row["candidate_status"] == "ncm_research_pending")

repository_snapshots = {}
for row in evaluations:
    if row["candidate_status"] == "candidate_found_direct":
        item = row["benchmark"]
        repository_snapshots[f'{item["official_repository_url"]}@{item["commit_sha"]}'] = {
            "url": item["official_repository_url"],
            "commit_sha": item["commit_sha"],
        }
    else:
        for item in row["rejected_repositories"]:
            repository_snapshots[f'{item["official_repository_url"]}@{item["commit_sha"]}'] = {
                "url": item["official_repository_url"],
                "commit_sha": item["commit_sha"],
            }

artifact = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-902-wave4",
    "generated_at": "2026-08-13T12:45:00+12:00",
    "audited_repository": "<local-user>/Herd\\oblivionfindings",
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Fourth bounded target-specific wave: 12 independently accepted direct material matches and 12 incomplete No Credible Match research candidates retained without credit.",
    "methodology": {
        "credit_rule": "Only target-specific official repositories pinned to immutable commits and exact source loci proving a material same-target slice receive verified credit.",
        "ncm_rule": "A completed NCM must retain the full target-specific search corpus considered, credible beyond-seed searches, official pinned repositories, exact inspected loci and target-specific rejection reasons. These 12 NCM candidates do not yet meet that rule and receive no credit.",
        "licence_rule": "Every credited or rejected repository records its root/community licence and edition boundary; no paid-only or excluded enterprise behavior is credited.",
        "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layout.",
        "runtime_boundary": "No application tests, browsers, databases, queues, jobs, deployments, Git history or product state were changed for this research wave.",
    },
    "input_pins": {
        "working_capability_manifest_902": {"path": "evidence/source/working-capability-manifest-902.json", "file_sha256": MANIFEST_SHA},
        "benchmark_final_902_before_wave": {"path": "evidence/source/benchmark-final-902-mapping.json", "file_sha256": BASE_MAPPING_SHA},
    },
    "repository_snapshots": dict(sorted(repository_snapshots.items())),
    "counts": {
        "evaluated": 24,
        "verified_benchmark_recommended": 12,
        "documented_ncm_recommended": 0,
        "ncm_research_pending": 12,
        "completion_credit_recommended": 12,
        "remains_unproved": 12,
    },
    "evaluations": evaluations,
    "integrity": {
        "evaluated_keys_unique": True,
        "evaluated_key_sha256": lines_sha(keys),
        "verified_key_sha256": lines_sha(candidate_keys),
        "ncm_key_sha256": lines_sha(ncm_keys),
        "candidate_rows_have_repo_sha_loci_slice_and_limits": all(
            row["benchmark"].get("official_repository_url")
            and len(row["benchmark"].get("commit_sha", "")) == 40
            and row["benchmark"].get("source_loci")
            and row["benchmark"].get("proven_slice")
            and row["benchmark"].get("parity_limits")
            and row.get("evidence_loci")
            for row in evaluations if row["candidate_status"] == "candidate_found_direct"
        ),
        "ncm_rows_retain_seed_repositories_searches_rejections_and_evidence": all(
            row.get("search_terms")
            and len(row.get("rejected_repositories", [])) >= 3
            and row.get("bounded_ncm_reason")
            and row.get("evidence_loci")
            for row in evaluations if row["candidate_status"] == "ncm_research_pending"
        ),
        "ncm_rows_withheld_from_completion_credit_pending_broader_search": all(
            row.get("completion_credit_recommended") is False
            and row.get("review_status") == "insufficient_beyond_seed_search_for_completion_credit"
            for row in evaluations if row["candidate_status"] == "ncm_research_pending"
        ),
        "manifest_lineage_snapshots_exact": all(row["current_source_lineage"] == lineage(row["working_key"]) for row in evaluations),
        "runtime_or_product_mutations": 0,
    },
    "completion_gate": {"complete": False, "reason": "Twelve direct rows are recommended for target-specific credit; twelve NCM candidates remain pending broader search; 588/902 targets will remain completion-unproved after direct-only integration."},
}

OUTPUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({
    "path": str(OUTPUT),
    "sha256": sha(OUTPUT),
    "counts": artifact["counts"],
    "evaluated_key_sha256": artifact["integrity"]["evaluated_key_sha256"],
    "verified_key_sha256": artifact["integrity"]["verified_key_sha256"],
    "ncm_key_sha256": artifact["integrity"]["ncm_key_sha256"],
}, indent=2))
