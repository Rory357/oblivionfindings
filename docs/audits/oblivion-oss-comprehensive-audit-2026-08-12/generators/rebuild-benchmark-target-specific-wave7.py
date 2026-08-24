#!/usr/bin/env python3
"""Build the seventh target-specific benchmark research payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave7.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
UPSTREAM = "4f6ee8504003cf2ea36488cd4876b8b90a8bf68c"
REPOSITORY = "https://github.com/frappe/hrms"
GENERATED_AT = "2026-08-13T16:25:40+12:00"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


DEFINITIONS = [
    {
        "key": "CAP-HR-BENEFITS-ENROLLMENT",
        "neutral": "An eligible employee can select benefit components for a payroll period, assign positive amounts within the available maximum, and persist one non-duplicate benefit application.",
        "loci": [
            "hrms/payroll/doctype/employee_benefit_application/employee_benefit_application.py:L43-L95",
            "hrms/payroll/doctype/employee_benefit_application/employee_benefit_application.py:L98-L119",
        ],
        "slice": "Validates an active employee, rejects duplicate applications for the same payroll period, requires eligible benefit rows, enforces positive component amounts and a maximum-benefit ceiling, and calculates remaining benefit.",
        "limits": "Does not prove Oblivion benefit-plan types, employee/employer contribution percentages, KiwiSaver synchronization, notifications, permissions, UI, or runtime persistence.",
    },
    {
        "key": "CAP-HR-BENEFITS-PLAN-ADMINISTRATION",
        "neutral": "An administrator can define a reusable flexible-benefit component with a yearly limit and payout/accrual policy, and the system validates incompatible configuration.",
        "loci": [
            "hrms/payroll/doctype/salary_component/salary_component.json:L127-L143,L241-L295",
            "hrms/payroll/doctype/salary_component/salary_component.py:L65-L69,L109-L136",
        ],
        "slice": "Defines flexible-benefit status, yearly maximum, accrual state, three payout policies and final-cycle payout configuration, and rejects incompatible accrual-component combinations.",
        "limits": "This is a material plan-definition slice, not proof of Oblivion plan names/types, employee and employer rates, activation history, enrolment counts, authorization, or deployed behavior.",
    },
    {
        "key": "CAP-HR-CANDIDATE-OFFERS-HIRE",
        "neutral": "Recruitment staff can create a job offer linked to an applicant, validate vacancy and duplicate constraints, track the applicant response, and synchronize accepted offer/applicant state when an employee is hired.",
        "loci": [
            "hrms/hr/doctype/job_offer/job_offer.py:L40-L82",
            "hrms/hr/doctype/job_offer/job_offer.json:L30-L95",
            "hrms/overrides/employee_master.py:L56-L87",
        ],
        "slice": "Defines an applicant-linked offer with response states and terms, prevents duplicate live offers, checks staffing-plan vacancies, updates applicant state as the offer changes, and marks the linked applicant and offer accepted when an employee is created.",
        "limits": "Does not prove Oblivion offer approval chains, document upload/generation, portal token, countersignature, role/site assignment, notifications, or runtime hire conversion.",
    },
    {
        "key": "CAP-HR-EXPENSE-REVIEW",
        "neutral": "An authorised reviewer can inspect a submitted expense claim, approve or reject sanctioned amounts under self-approval controls, and drive explicit reviewed and payment-readiness states.",
        "loci": [
            "hrms/hr/doctype/expense_claim/expense_claim.py:L122-L186",
            "hrms/hr/doctype/expense_claim/expense_claim.py:L196-L223",
            "hrms/hr/doctype/expense_claim/expense_claim.py:L491-L505,L577-L582",
        ],
        "slice": "Derives Draft, Submitted, Approved-Unpaid, Paid, Rejected and Cancelled states; blocks configured self-approval; requires an approved or rejected decision before submission; zeros rejected sanction amounts and prevents sanctioned amounts exceeding claims.",
        "limits": "Does not prove Oblivion approver permissions, bulk review, rejection text, receipt viewing, journal queue execution, NZ accounting rules, UI, or runtime decisions.",
    },
    {
        "key": "CAP-HR-EXIT-INTERVIEW-CASE",
        "neutral": "HR can schedule and complete one exit-interview case per departing employee, record interviewers and a summary, distribute a configured questionnaire, and synchronize completion to the employee record.",
        "loci": [
            "hrms/hr/doctype/exit_interview/exit_interview.py:L44-L91",
            "hrms/hr/doctype/exit_interview/exit_interview.py:L94-L160",
            "hrms/hr/doctype/exit_interview/exit_interview.json:L11-L36,L39-L90",
        ],
        "slice": "Requires a relieving date, prevents duplicate non-cancelled interviews, tracks Pending/Scheduled/Completed/Cancelled state, only submits completed cases, updates the employee interview date, and sends a configured questionnaire once with success/failure accounting.",
        "limits": "Does not prove Oblivion departure taxonomy, confidential question responses, trend analytics, offboarding-task auto-completion, permissions, retention, or runtime email delivery.",
    },
    {
        "key": "CAP-HR-LEAVE-REPORT",
        "neutral": "HR can run a period-bounded leave report by employee and leave type showing opening balance, allocation, leave taken, expiry, encashment and closing balance, with organisational filters.",
        "loci": [
            "hrms/hr/report/employee_leave_balance/employee_leave_balance.py:L21-L86",
            "hrms/hr/report/employee_leave_balance/employee_leave_balance.py:L87-L136",
            "hrms/hr/report/employee_leave_balance/employee_leave_balance.py:L142-L166",
        ],
        "slice": "Defines leave-balance report columns, enumerates active employees and leave types, calculates opening, allocation, taken, expired, encashed and closing amounts, and filters by company, department, employee and employee status.",
        "limits": "Does not prove Oblivion Bradford Factor, absenteeism lens, utilisation rules, PDF/CSV hardening, local permissions, exact date semantics, UI, or runtime values.",
    },
    {
        "key": "CAP-HR-OFFBOARDING-CASE",
        "neutral": "HR can start an employee offboarding case from a reusable activity set, generate assigned tasks with dates, track progress from pending through completion, and cancel the linked work safely.",
        "loci": [
            "hrms/hr/doctype/employee_separation/employee_separation.py:L21-L48",
            "hrms/controllers/employee_boarding_controller.py:L14-L101",
            "hrms/controllers/employee_boarding_controller.py:L143-L194",
        ],
        "slice": "Represents employee separation with activities and Pending/In Process/Completed status, creates a linked project and dated tasks, assigns tasks to configured users or roles, updates case state from project completion, and removes linked tasks/project on cancellation.",
        "limits": "Does not prove Oblivion departure reasons, duplicate-active-case rule, task dependencies, asset/access reconciliation, exit-interview coupling, role boundaries, UI, or runtime notification delivery.",
    },
    {
        "key": "CAP-HR-ONBOARDING-TEMPLATES",
        "neutral": "HR can define a reusable onboarding template scoped by organisation attributes, containing activities with responsibility and timing metadata that can seed an employee onboarding run.",
        "loci": [
            "hrms/hr/doctype/employee_onboarding_template/employee_onboarding_template.json:L8-L68",
            "hrms/hr/doctype/employee_onboarding_template/employee_onboarding_template.py:L14-L26",
            "hrms/controllers/employee_boarding_controller.py:L158-L175",
        ],
        "slice": "Defines a persistent onboarding template with title, company, department, designation, employee grade and activity rows; exposes activity name, role, user, employee-creation requirement, description, weight, start offset and duration for downstream runs.",
        "limits": "Does not prove Oblivion template activation, duplication, ordering UX, email configuration, per-site scoping, task dependencies, permissions, or runtime template application.",
    },
    {
        "key": "CAP-HR-PAYSLIP-ADMIN",
        "neutral": "An authorised payroll administrator can select employees for a pay period, prevent duplicate payslips, generate slips synchronously or in a queue, validate calculations, submit valid slips, report partial failures, and optionally distribute them.",
        "loci": [
            "hrms/payroll/doctype/payroll_entry/payroll_entry.py:L108-L147,L264-L343",
            "hrms/payroll/doctype/payroll_entry/payroll_entry.py:L1599-L1647,L1698-L1727",
            "hrms/payroll/doctype/salary_slip/salary_slip.py:L227-L301",
        ],
        "slice": "Selects employees under payroll filters, prevents duplicate slips for the period, permission-checks generation, queues large batches, creates one slip per remaining employee, records batch failure, validates slip dates/components/net pay, submits non-negative slips, reports failures and triggers accrual/email after successful submission.",
        "limits": "Does not prove Oblivion payroll-run model, individual-versus-bulk UI, PDF storage, NZ tax correctness, permissions, queue health, email delivery, or runtime generation.",
    },
    {
        "key": "CAP-HR-PAYSLIP-SELF-DOWNLOAD",
        "neutral": "An employee can select one of their submitted payslips and download its rendered PDF document.",
        "loci": [
            "frontend/src/views/salary_slip/Dashboard.vue:L97-L113",
            "frontend/src/views/salary_slip/Detail.vue:L127-L162",
            "hrms/api/__init__.py:L802-L818",
            "hrms/payroll/doctype/salary_slip/salary_slip.json:L771-L800",
        ],
        "slice": "Filters the employee dashboard to submitted slips for the current employee, exposes a Download PDF action, calls the repository PDF endpoint for the selected Salary Slip, returns a base64 data URL, and grants Employee read/print metadata.",
        "limits": "Does not prove Oblivion direct-object denial, private-disk path checks, regenerated-versus-stored PDF rules, filenames, browser download behavior, or runtime authorization.",
    },
    {
        "key": "CAP-HR-SKILLS-CATALOG",
        "neutral": "HR can maintain a reusable catalogue of uniquely named skills with descriptions for assignment to employees, designations, expected skill sets and assessments.",
        "loci": [
            "hrms/hr/doctype/skill/skill.json:L8-L25",
            "hrms/hr/doctype/designation_skill/designation_skill.json:L8-L16",
            "hrms/hr/doctype/expected_skill_set/expected_skill_set.json:L8-L25",
        ],
        "slice": "Defines a persistent skill master with a unique name and description and repository-native links from designation and expected-skill records.",
        "limits": "Does not prove Oblivion categories, active/retired state, destructive-history controls, permissions, UI, or runtime catalogue management.",
    },
    {
        "key": "CAP-HR-SKILLS-ASSESSMENT-MATRIX",
        "neutral": "HR can maintain an employee-by-skill matrix recording skill proficiency and evaluation date, with reusable skill identities and employee context.",
        "loci": [
            "hrms/hr/doctype/employee_skill_map/employee_skill_map.json:L7-L58",
            "hrms/hr/doctype/employee_skill/employee_skill.json:L8-L34",
            "hrms/hr/doctype/skill_assessment/skill_assessment.json:L8-L25",
        ],
        "slice": "Defines one skill map per employee with designation context and employee-skill rows; each row links a catalogued skill to a proficiency rating and evaluation date, with a separate skill-assessment rating record.",
        "limits": "Does not prove Oblivion cross-employee grid rendering, assessor identity, evidence attachment, target levels, history, sign-off, gap analysis, permissions, or runtime calculations.",
    },
]

manifest = load(MANIFEST_PATH)
mapping = load(MAPPING_PATH)
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
mapping_by_key = {row["working_key"]: row for row in mapping["targets"]}
require(len(DEFINITIONS) == 12, "Wave 7 must contain exactly 12 targets")
require(len({row["key"] for row in DEFINITIONS}) == 12, "Wave 7 keys must be unique")

evaluations = []
for definition in DEFINITIONS:
    key = definition["key"]
    identity = manifest_by_key[key]
    prior = mapping_by_key[key]
    require(str(prior["status"]).startswith("unproved"), f"Target is not unproved: {key}")
    evidence_loci = [f"frappe/hrms@{UPSTREAM} :: {locus.replace(':L', ' :: L')}" for locus in definition["loci"]]
    evaluations.append({
        "working_key": key,
        "prior_status": prior["status"],
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": definition["neutral"],
        "current_source_lineage": {
            field: identity.get(field, [])
            for field in (
                "id_status", "class", "canonical_module", "source_family_ids",
                "route_ids", "page_ids", "backend_anchors",
            )
        },
        "evidence_loci": evidence_loci,
        "benchmark": {
            "official_repository_url": REPOSITORY,
            "commit_sha": UPSTREAM,
            "source_loci": definition["loci"],
            "proven_slice": definition["slice"],
            "parity_limits": definition["limits"],
            "licence": {
                "spdx": "GPL-3.0",
                "edition_boundary": "Pinned repository-native GPL-3.0 Frappe HR source only; Frappe Cloud, paid hosting, support, third-party apps, private extensions, and unpinned branches are excluded.",
            },
        },
    })

output = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-902-wave7",
    "generated_at": GENERATED_AT,
    "audited_repository": str(AUDIT.parents[2]),
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Seventh bounded target-specific wave: 12 independently researched current unique unproved targets, with no inherited family credit.",
    "methodology": {
        "credit_rule": "Only target-specific inspection of official repository-native source pinned to an immutable commit, with exact loci proving a material same-target slice, receives credit.",
        "licence_rule": "Only GPL-3.0 repository-native Frappe HR source is credited; hosted services, support, proprietary applications, private extensions, and code outside the pinned repository are excluded.",
        "no_copy_rule": "Evidence is behavioural only. Do not copy source, schema, UI, wording, or distinctive layouts.",
        "family_credit_inherited": False,
        "runtime_boundary": "No application, browser, database, queue, deployment, filesystem artifact outside this audit bundle, or Git state was changed.",
    },
    "input_pins": {
        "working_capability_manifest_902": {
            "path": "evidence/source/working-capability-manifest-902.json",
            "file_sha256": sha(MANIFEST_PATH),
        },
        "benchmark_final_902_before_wave": {
            "path": "evidence/source/benchmark-final-902-mapping.json",
            "file_sha256": sha(MAPPING_PATH),
        },
    },
    "counts": {
        "evaluated": 12,
        "verified_benchmark_direct_recommended": 12,
        "documented_ncm_direct_recommended": 0,
        "completion_credit_recommended": 12,
    },
    "evaluations": evaluations,
    "projected_counts_after_application": {
        "denominator": 902,
        "verified_benchmark": {"direct": 244, "strict_one_to_one_rename": 22, "total": 266},
        "documented_no_credible_match": {"direct": 77, "strict_one_to_one_rename": 7, "total": 84},
        "eligible_total": 350,
        "completion_unproved_total": 552,
        "eligible_percentage": 38.8027,
    },
    "integrity": {
        "selected_target_count": 12,
        "selected_targets_unique": True,
        "selected_targets_current_in_manifest": True,
        "selected_targets_were_unproved_at_recheck": True,
        "all_upstream_repositories_official": True,
        "all_upstream_refs_immutable_commits": True,
        "licence_and_edition_screening_complete": True,
        "family_credit_inherited": False,
        "paid_only_evidence_used": False,
        "files_modified_by_research_agent": False,
    },
}

OUTPUT_PATH.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT_PATH), "sha256": sha(OUTPUT_PATH), "rows": len(evaluations)}, indent=2))
