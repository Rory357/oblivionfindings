#!/usr/bin/env python3
"""Build the ninth target-specific benchmark research payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave9.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-14T10:51:49+12:00"
PRE_WAVE_MAPPING_SHA = "347e8cbf78249db0d0f6ee648c992b6a639e2bbe15547e218436219852de9171"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


REPOS = {
    "ERPNext": {
        "official_repository_url": "https://github.com/frappe/erpnext",
        "commit_sha": "081d269f6d7796adcc297d75ed4b0190c236b106",
        "spdx": "GPL-3.0",
        "license_locus": "license.txt",
        "license_sha256": "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986",
        "edition_boundary": "Pinned GPL-3.0 ERPNext repository-native community source only; Frappe Cloud hosting, paid support, private apps, proprietary extensions and unpinned branches are excluded.",
    },
    "Frappe_HRMS": {
        "official_repository_url": "https://github.com/frappe/hrms",
        "commit_sha": "fe46f9ba16881f9bf49971f6fcdf6e04b0b7cfb0",
        "spdx": "GPL-3.0",
        "license_locus": "license.txt",
        "license_sha256": "f333043685c88280b1a0a41b4f8e2eacb02079f0bfca4d467e52c8834c658cea",
        "edition_boundary": "Pinned GPL-3.0 Frappe HRMS repository-native community source plus its declared ERPNext dependency only; Frappe Cloud hosting, paid support, private apps, proprietary extensions and unpinned branches are excluded.",
    },
    "Odoo_Community": {
        "official_repository_url": "https://github.com/odoo/odoo",
        "commit_sha": "62e1c17ceb711a566e42d7f7be2d249d4278dc4c",
        "spdx": "LGPL-3.0",
        "license_locus": "LICENSE",
        "license_sha256": "abc09dad5f84a76e1b0279237053cae16c03228ab27d8d467677054c2bd17eeb",
        "edition_boundary": "Pinned LGPL-3.0 Odoo Community repository source only; Enterprise, hosted Odoo Online behavior, proprietary modules and unpinned branches are excluded.",
    },
    "Traccar": {
        "official_repository_url": "https://github.com/traccar/traccar",
        "commit_sha": "17e7a330e8a07896f000898b37dc770f2df3c142",
        "spdx": "Apache-2.0",
        "license_locus": "LICENSE.txt",
        "license_sha256": "cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30",
        "edition_boundary": "Pinned Apache-2.0 Traccar server repository source only; hosted tracking service, commercial support, separately distributed clients and unpinned branches are excluded.",
    },
}


# key, prior status, repository, loci(path, lines, file SHA), neutral requirement,
# proven material slice, parity limits, P6/no-copy caveat.
DIRECT = [
    ("CAP-FLEET-VEHICLE-REGISTER", "unproved", "ERPNext",
     [("erpnext/setup/doctype/vehicle/vehicle.json", "L1-L29,L40-L112,L243-L248", "c8739148e00245726b166ee2beca98816862c97311b2f2cdefa0254386434fd2")],
     "Maintain an authorised vehicle register with a stable vehicle identity, registration, make/model, odometer, location, assignment and change history.",
     "Vehicle uses licence plate as its stable name and materially records make, model, last odometer, location, chassis, assigned employee and insurance fields with tracked changes.",
     "Does not prove Oblivion bulk actions, Site-scoped visibility, wheelchair/medical equipment fields, live tracker state, NZ WOF/COF rules or direct-object authorization.",
     "Benchmark-only behavior; do not copy schema, labels or layout. Validate NZ fleet compliance and disability-support context independently."),
    ("CAP-FLEET-VEHICLE-FUEL", "unproved", "Frappe_HRMS",
     [("hrms/hr/doctype/vehicle_log/vehicle_log.json", "L49-L65,L85-L131,L155-L161", "a3c095cfd19b2b9bedf4d3761e6eddef5c3ecf8ce19b56ca103cd37846bf0703"), ("hrms/hr/doctype/vehicle_log/vehicle_log.py", "L38-L47,L73-L95", "7c0e5edebc5f38c08e33ec5edfcc411adcc8dc591973c4c3017e8d6f4629960f"), ("hrms/hr/report/vehicle_expenses/vehicle_expenses.py", "L23-L60,L63-L100", "722f50347caa7ff7a7901593cd9a4a505002f6503b0470cc7456c38f1dc9703e")],
     "Record and retrieve vehicle-linked refuelling entries with date, odometer, quantity, price, supplier/invoice context and attributable driver or employee.",
     "Vehicle Log binds vehicle, employee, date and monotonic odometer to fuel quantity and price; submit updates vehicle odometer, and the report derives fuel expense with employee, vehicle and date filters.",
     "Does not prove fuel-card integration, receipt immutability, duplicate detection, Site scope, approval, emissions reporting or Oblivion's exact fuel-entry route behavior.",
     "Accounting-oriented fleet evidence is not proof of NZ tax treatment, accessibility, privacy or safe direct-object handling."),
    ("CAP-FLEET-VEHICLE-TRIPS", "unproved", "Traccar",
     [("src/main/java/org/traccar/reports/TripsReportProvider.java", "L58-L79", "207259d9afd3fb08f9845c9fa07776820091ddd470a183670d6da13964b122ef"), ("src/main/java/org/traccar/reports/model/BaseReportItem.java", "L21-L125", "4952dfa0efc283c2ac02191867f01abb76c0235846be372a6abbf5a5306046da"), ("src/main/java/org/traccar/reports/model/TripReportItem.java", "L19-L117", "4317a3356fd439fe9a4e2876fdc9759fe28183f003d9855bd337432fcd426637")],
     "Retrieve authorised vehicle trips with device/vehicle identity, start/end times, start/end positions or addresses, duration, distance, speed and driver context.",
     "The provider enforces accessible-device selection and period limits before detecting trips; report models carry device, distance, speed, odometer, times, endpoints, addresses, duration and driver identity.",
     "Does not prove Oblivion's personal/business toggle, consent boundary, Site custody, correction provenance, tracker ingestion reliability or healthcare transport context.",
     "Location and driver evidence is privacy-sensitive; a tracking benchmark cannot establish lawful care-sector tracking or NZ privacy compliance."),
    ("CAP-FLEET-DRIVER-DIRECTORY", "unproved", "ERPNext",
     [("erpnext/setup/doctype/driver/driver.json", "L12-L30,L39-L51,L66-L103,L119-L128,L178-L186", "d19ce43793753f89aa7879b208803d36fca04e29f8adfcf4117ba3fe2262ea66")],
     "Maintain an authorised searchable driver directory with person linkage, active status, contact, licence identifier, issue/expiry dates and permitted licence categories.",
     "Driver records expose full name, active/suspended/left status, employee/user linkage, contact, licence number, issue/expiry dates and categories with list/search fields.",
     "Does not prove NZ licence checks, medical/competency eligibility, Site-specific directory visibility, expiry escalation, driving scorecard or direct-object denial.",
     "Licence and contact data are sensitive HR records; benchmark presence is not authorization, retention or accessibility proof."),
    ("FLEET-MILEAGE", "unproved_pending", "Frappe_HRMS",
     [("hrms/hr/doctype/vehicle_log/vehicle_log.py", "L38-L69,L73-L95", "7c0e5edebc5f38c08e33ec5edfcc411adcc8dc591973c4c3017e8d6f4629960f"), ("hrms/hr/doctype/expense_claim/expense_claim.py", "L55-L93,L122-L154,L196-L226,L264-L283", "10c34680b0a09321b311ca03c9a33e8461c30640611bb01fdf76e5167d0d78c0")],
     "Record employee vehicle mileage from monotonic odometer evidence, derive reimbursable vehicle expense, submit it into an approval/accounting lifecycle and preserve reversal behavior.",
     "Vehicle Log rejects odometer rollback, updates the vehicle on submit, reverses distance on cancel and constructs a linked Expense Claim; Expense Claim supplies approval/payment states and accounting entries.",
     "Does not prove Oblivion's exact approve/reject/mark-paid controls, NZ IRD mileage rates, Site ownership, duplicate journey checks, employee attestations or exported evidence format.",
     "Tax and reimbursement rules are jurisdiction-specific; no rate, regulatory or payroll suitability is inferred."),
    ("CAP-HR-CALENDAR-EVENT-MANAGEMENT", "unproved", "Odoo_Community",
     [("addons/calendar/models/calendar_event.py", "L70-L81,L131-L215,L583-L606,L768-L805,L930-L952", "2eca7a2f229c74b2a836710f0ea00ba1eeb95d5ac86605987bed114964a7af59")],
     "Authorised users can create, update, retrieve and remove calendar events with subject, organiser, time, location, privacy, attendees and reminders.",
     "Calendar Event defines subject, description, organiser, location, privacy, availability, times, attendees and alarms, with repository-native create, update and delete behavior.",
     "Does not prove Oblivion attachment lifecycle, HR record binding, Site scope, event-type policy, download authorization, recurrence UX or audit evidence.",
     "Generic community calendar behavior only; HR privacy, NZ employment obligations and accessibility require separate validation."),
    ("CAP-HR-CALENDAR-PARTICIPATION", "unproved", "Odoo_Community",
     [("addons/calendar/models/calendar_attendee.py", "L16-L46,L57-L80,L115-L124,L225-L247", "a9682e5485d0b304fd6ca512d15ed7b1d33057c07b03ffcaff06696eb1c5e52e")],
     "An invited participant can respond to an authorised calendar event with accepted, declined or tentative state and retain attributable participation evidence.",
     "Calendar Attendee links an identified partner to an event and persists needs-action, accepted, declined or tentative state; accept/decline actions post attributable messages and update state.",
     "Does not prove Oblivion RSVP authorization, Site boundaries, event visibility after revocation, attachment privacy, notification recovery or correction history.",
     "Participation can disclose employment information; generic calendar behavior does not establish lawful HR disclosure or retention."),
    ("CAP-HR-EMPLOYEE-PROFILE-ACCOUNT", "unproved", "ERPNext",
     [("erpnext/setup/doctype/employee/employee.json", "L27-L34,L175-L183,L208-L216,L283-L304,L364-L372", "238e600228dd3d0b842d2fd3915d64f0edea708fdc2cd9ee2edf8966fb2671c7"), ("erpnext/setup/doctype/employee/employee.py", "L435-L482", "5e2823056a5df0b75123bdc00cfb5300028cf9767495f264338d957b72ff3d67")],
     "Govern the linkage between an employee profile and a login account, create the account from an authorised employee record and constrain the resulting employee access.",
     "Employee stores a linked User ID and optional automatic creation; the POST-only create-user operation checks write permission, rejects duplicate linkage, validates email, creates User, assigns Employee role and can add employee/company permissions.",
     "Does not prove Oblivion invitation delivery, expiry, replay prevention, Site assignment, role composition or generic direct-object concealment.",
     "Account creation is security-sensitive; benchmark evidence does not authorize reset links as invitations or establish Oblivion's access model."),
    ("CAP-HR-OFFBOARDING-TASK", "unproved", "Frappe_HRMS",
     [("hrms/hr/doctype/employee_separation/employee_separation.json", "L53-L59,L73-L91,L119-L152", "f2c609736864fdb75b4254c274ad1e6c1e9c986b98bceba269fd706cf1695db6"), ("hrms/controllers/employee_boarding_controller.py", "L14-L18,L26-L100,L114-L141,L178-L199", "ec3f0ea0ffdfcdbbf3034dfd1d04748aa7fff28d499b7d4888817d0226a1fe79")],
     "Instantiate employee offboarding activities as assigned tasks, track each task through completion and derive the parent offboarding status from task/project completion.",
     "Employee Separation stores pending/in-process/completed state and activities; submission creates a project and dated tasks, assigns configured users or roles, and task/project progress updates parent separation status.",
     "Does not prove Oblivion evidence upload, sign-off user, access revocation, Site scope, actor/time provenance, idempotent completion or credential/device recovery.",
     "Offboarding can affect employment rights and system access; this workflow is not a legal or security termination policy."),
    ("CAP-HR-CAREERS-PUBLIC-APPLICATION", "unproved", "Frappe_HRMS",
     [("hrms/hr/doctype/job_opening/job_opening.py", "L19-L58,L65-L70,L161-L174", "d460d7d11d99cc58721c4a947a3eecfc6f32d9c79ce39cedd6bde3449b627c59"), ("hrms/templates/generators/job_opening.html", "L4-L40,L45-L199,L201-L220", "6ff7154cc8a80504355a131df0ff8c4e4078cf20cad8999c0f6e59ae7c5a30ed"), ("hrms/hr/web_form/job_application/job_application.json", "L1-L38,L38-L151", "8ff10fdd44e6b42a681a503508f89205a579a558ed70bf397c547cfd92eff963"), ("hrms/hr/doctype/job_applicant/job_applicant.json", "L42-L108,L128-L136,L185-L191", "f252c8ed9a05dcd4b2d47774f7e636b461da302208fd9491231106cab8b64b63")],
     "Publish open job information and allow an unauthenticated candidate to submit an application linked to the opening with identity, contact, cover letter and resume evidence.",
     "Published Job Opening has a stable public route and closing state; its template displays the opening and Apply action; the public non-login form creates Job Applicant data with name, email, opening, cover letter and resume evidence.",
     "Does not prove Oblivion token secrecy, anti-abuse, malware scanning, privacy notice/consent, duplicate policy, accessibility or candidate communication lifecycle.",
     "Candidate data is sensitive and employment decisions high-impact; public-form similarity does not prove privacy, fairness, security or NZ employment-law compliance."),
    ("HR-POSITION", "unproved_pending", "ERPNext",
     [("erpnext/setup/doctype/designation/designation.json", "L1-L29", "772d13319c3367c177f51394bae0132a23ba2216ce3a74144eaa51d68c52fa25"), ("erpnext/setup/doctype/employee/employee.json", "L36-L42,L364-L372", "238e600228dd3d0b842d2fd3915d64f0edea708fdc2cd9ee2edf8966fb2671c7")],
     "Maintain an authorised catalogue of uniquely named organisational positions with a human-readable description for assignment to employee records.",
     "Designation is a setup record with required unique name and description, and Employee materially links to that designation.",
     "Does not prove Oblivion reporting lines, Site assignment, headcount/vacancy controls, effective dating, approvals, deletion rules or position history.",
     "This is a narrow catalogue match; do not infer broader position-management parity or copy field/layout choices."),
    ("CAP-HR-EXPENSE-PAYMENT", "unproved", "Frappe_HRMS",
     [("hrms/hr/doctype/expense_claim/expense_claim.py", "L55-L93,L122-L154,L196-L226,L264-L283,L333-L354", "10c34680b0a09321b311ca03c9a33e8461c30640611bb01fdf76e5167d0d78c0"), ("hrms/public/js/erpnext/payment_entry.js", "L4-L60,L64-L90", "cc262abeb709392aac356c649c5b224f645c00ce18707b70271e27dd908619f4")],
     "Disburse an approved employee expense against its payable/accounting record, update reimbursed and paid state and preserve reversal linkage.",
     "Expense Claim has approved/rejected and paid/unpaid states, tracks reimbursed totals, posts employee payable GL entries and payment-side accounting; Payment Entry restricts employee references to unpaid Expense Claims.",
     "Does not prove Oblivion's exact mark-paid precondition, maker/checker independence, bank execution, payment evidence, Site scope, concurrency/idempotency, notification or recovery behavior.",
     "Accounting workflow evidence is not financial-control assurance; segregation, NZ accounting/tax rules and payment authorization need independent verification."),
]


manifest = load(MANIFEST_PATH)
mapping = load(MAPPING_PATH)
require(manifest.get("audited_commit") == COMMIT, "Manifest commit mismatch")
require(mapping.get("audited_commit") == COMMIT, "Mapping commit mismatch")
require(sha(MAPPING_PATH) == PRE_WAVE_MAPPING_SHA, "Pre-wave mapping SHA mismatch")
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
mapping_by_key = {row["working_key"]: row for row in mapping["targets"]}
require(len(manifest_by_key) == len(mapping_by_key) == 902, "Target identity count mismatch")

keys = [row[0] for row in DIRECT]
require(len(keys) == len(set(keys)) == 12, "Wave-9 keys are not 12 unique targets")
keys_sha = hashlib.sha256("\n".join(sorted(keys)).encode()).hexdigest()
require(keys_sha == "f269ad458ac5434605621dbe5b3ebb5c0801ad09375446319df1c809ee1ab367", "Wave-9 key SHA drift")

evaluations = []
for key, prior_status, repo_name, loci, neutral, proven, limits, caveat in DIRECT:
    identity = manifest_by_key[key]
    prior = mapping_by_key[key]
    require(prior.get("status") == prior_status, f"Prior status drift: {key}")
    require(prior.get("completion_credit") is False, f"Target already credited: {key}")
    repo = REPOS[repo_name]
    exact_loci = []
    evidence_loci = []
    for path, lines, file_sha in loci:
        url = f"{repo['official_repository_url']}/blob/{repo['commit_sha']}/{path}"
        exact_loci.append({"path": path, "lines": lines, "sha256": file_sha, "primary_source_url": url})
        evidence_loci.append(f"{repo['official_repository_url']}@{repo['commit_sha']} :: {path} :: {lines} :: sha256={file_sha}")
    lineage = {name: identity.get(name, []) for name in ("source_family_ids", "route_ids", "page_ids", "backend_anchors")}
    lineage.update({name: identity.get(name) for name in ("id_status", "class", "canonical_module")})
    evaluations.append({
        "working_key": key,
        "prior_status": prior_status,
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": neutral,
        "current_source_lineage": lineage,
        "benchmark": {**repo, "repo": repo_name, "exact_loci": exact_loci, "proven_slice": proven, "parity_limits": limits, "p6_caveats": caveat},
        "evidence_loci": evidence_loci,
    })

lineage_lines = sorted("|".join((row["working_key"], row["prior_status"], ";".join(sorted(row["current_source_lineage"]["route_ids"])), ";".join(sorted(row["current_source_lineage"]["page_ids"])), ";".join(sorted(row["current_source_lineage"]["backend_anchors"])))) for row in evaluations)
lineage_sha = hashlib.sha256("\n".join(lineage_lines).encode()).hexdigest()
require(lineage_sha == "1c9d96cd458673c6a56e16b8db0fee9b648029543d95de5ba196dcec81b59a99", "Wave-9 lineage SHA drift")

artifact = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-902-wave9",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Ninth bounded target-specific wave: 12 current unique completion-unproved targets, with no prior-wave reuse or inherited family credit.",
    "methodology": {
        "credit_rule": "Only target-specific official repository-native source pinned to an immutable commit, with exact file hashes and loci proving a material same-target slice, receives credit.",
        "licence_rule": "Only cited community source is credited; hosted, paid, enterprise, private and unpinned behavior is excluded.",
        "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layouts.",
        "family_credit_inherited": False,
        "runtime_boundary": "No application, browser, database, deployment or Git state was changed.",
    },
    "input_pins": {
        "working_capability_manifest_902": {"path": "evidence/source/working-capability-manifest-902.json", "file_sha256": sha(MANIFEST_PATH)},
        "benchmark_final_902_before_wave": {"path": "evidence/source/benchmark-final-902-mapping.json", "file_sha256": sha(MAPPING_PATH)},
    },
    "repository_snapshots": REPOS,
    "counts": {"evaluated": 12, "verified_benchmark_direct_recommended": 12, "documented_ncm_direct_recommended": 0, "completion_credit_recommended": 12},
    "selected_keys_sha256": keys_sha,
    "selected_lineage_tuple_sha256": lineage_sha,
    "evaluations": evaluations,
    "projected_delta": {"verified_benchmark_direct": 12, "eligible_total": 12, "completion_unproved": -12},
}
OUTPUT_PATH.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT_PATH), "sha256": sha(OUTPUT_PATH), "evaluated": 12, "direct": 12}, indent=2))
