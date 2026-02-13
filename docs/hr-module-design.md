# Oblivion Findings — HR Module: Comprehensive Design Document

**Version:** 1.0
**Date:** 2026-02-12
**Author:** Architecture Team
**Stack:** Laravel 11 + Inertia.js + React/TypeScript + Shadcn UI
**Context:** NZ supported living / disability support provider

---

## 0) EXECUTIVE SUMMARY

### What the HR Module Does
A unified people-management system covering the full employee lifecycle: recruitment → onboarding → ongoing compliance → rostering/leave → timesheets → performance → offboarding. It introduces a **role-based compliance matrix engine** that gates rostering eligibility, tracks training/vetting expiry, and generates audit-ready evidence packs for NZ regulatory frameworks (Ngā Paerewa NZS 8134:2021, Privacy Act 2020, HSWA).

### How It Integrates
| Existing Module | Integration Point |
|---|---|
| **Rostering/Shifts** | Compliance matrix gates shift assignment; leave blocks availability; fatigue rules warn/block |
| **Timesheets** | Team leader approval workflow; allowance rules; payroll export locking |
| **Assets** | Issue/return tracking linked to AssetAssignment; offboarding auto-checklist |
| **Incidents** | Staff incidents link to HR cases; confidential HR-only incident types |
| **Control Room** | Staff-related alerts surface in HR dashboard |
| **Documents** | HR document templates; restricted-access HR files |
| **Notifications** | Expiry reminders, approval requests, onboarding tasks |
| **Audit Log** | Every HR action audited via existing AuditableChanges trait + AuditLogger |

### Why It's Compliant
- **Privacy Act 2020**: Consent capture at recruitment; work-email-only after hire; access logging on sensitive records
- **NZ Police Vetting**: Status tracking with approved agency workflow assumptions; consent capture; secure result storage (HR-only)
- **Ngā Paerewa NZS 8134:2021**: Training/competency evidence; compliance matrix per role; audit evidence packs
- **Good Faith Employment**: Step-by-step disciplinary templates with "good faith" checklist prompts; versioned decision records

### MVP vs Phased Roadmap

| Phase | Scope | Duration Estimate |
|---|---|---|
| **MVP (Phase 1)** | Employee profiles, compliance matrix, training tracking, vetting register, onboarding checklists, basic leave | First |
| **Phase 2** | Recruitment pipeline, performance/supervision, policy attestations, HR case management | Second |
| **Phase 3** | Disciplinary workflows, payroll export, document templates, evidence packs, wellbeing dashboard | Third |
| **Phase 4** | Driver eligibility gating, roster integration (compliance blocks), advanced reporting | Fourth |

---

## 1) CURRENT CODEBASE CHECK

### What Already Exists vs What Must Be Added

| Area | Exists | Status | Must Add |
|---|---|---|---|
| **User Model** | `users` table with RBAC, `approved_at` gate | Complete | HR-specific fields (work_email, personal_email, employee_number) |
| **StaffProfile** | phone, job_title, employment_type, start_date, is_active | Minimal | Extend: emergency contacts, contract details, end_date, offboarding |
| **StaffCredential** | type, issuer, issued_at, expires_at, reference | Complete | Add: `compliance_requirement_id` FK for matrix linkage |
| **StaffAvailability** | Weekly recurring slots (day_of_week, starts_at, ends_at) | Complete | No changes needed |
| **StaffTimeOff** | starts_at, ends_at, type (leave/unavailable/training) | Basic | Add: approval workflow, leave_type, balance tracking |
| **StaffBackgroundCheck** | Full vetting model with risk assessment | Complete | Add: consent_captured_at, consent_method, nz_police_vetting_ref |
| **TrainingCourse** | Full course catalog with renewal/prerequisites | Complete | Add: `is_induction` flag |
| **StaffTrainingRecord** | Full enrollment/completion/renewal tracking | Complete | No changes needed |
| **CompetencyFramework** | Framework + items with proficiency levels | Complete | No changes needed |
| **StaffInduction** | Migration exists, NO model class | Incomplete | Create model class + controller |
| **StaffCompetencyAssessment** | Migration exists, NO model class | Incomplete | Create model class |
| **RBAC** | 17 roles, 60+ permissions, `hr` role defined | Complete | Add HR-specific permissions |
| **Shifts/Rostering** | Full scheduling with conflict detection | Complete | Add compliance eligibility check hook |
| **Timesheets** | Full approval workflow with bulk ops | Complete | Add payroll export + allowance fields |
| **Assets** | AssetAssignment with polymorphic assignee | Complete | No changes for MVP |
| **Incidents** | Full workflow with templates/follow-ups | Complete | Add `is_hr_confidential` flag |
| **Documents** | ClientDocument, SiteDocument, AssetDocument | Complete | Add HrDocument model |
| **Notifications** | 9+ classes, database+email channels | Complete | Add HR notification classes |
| **AuditLog** | AuditableChanges trait + AuditLogger service | Complete | No changes needed |
| **Compliance Engine** | ComplianceObligation for org-level | Complete | New: role-based staff compliance matrix |
| **Recruitment** | Nothing | Missing | Full pipeline needed |
| **Leave Management** | Basic StaffTimeOff only | Minimal | Approval workflow, balances, types |
| **Performance Reviews** | Governance module has PerformanceReview | Exists but governance-focused | HR-focused 1:1s, supervision, probation reviews |
| **Disciplinary** | Nothing | Missing | Full workflow needed |
| **Policy Attestations** | Nothing | Missing | Library + acknowledgement tracking |
| **HR Cases** | Nothing | Missing | Case management with confidentiality |
| **Payroll Export** | Nothing | Missing | Export format + locking |
| **Driver Eligibility** | Nothing structured | Missing | Licence register + eligibility flag |
| **Wellbeing/EAP** | Nothing | Missing | Dashboard + resource links |

---

## 2) HR DOMAIN BOUNDARY & DATA OWNERSHIP

### HR OWNS (source of truth)

| Domain | Key Tables |
|---|---|
| Candidate pipeline | `hr_candidates`, `hr_applications`, `hr_interviews`, `hr_offers` |
| Employee Profile (HR record) | `hr_employee_profiles` (extends StaffProfile) |
| Compliance matrix | `hr_compliance_requirements`, `hr_compliance_matrix`, `hr_staff_compliance_status` |
| Training oversight | Orchestrates existing `training_courses` + `staff_training_records` |
| Vetting register | Orchestrates existing `staff_background_checks` |
| Driving eligibility | `hr_driver_eligibility` |
| Leave requests & balances | `hr_leave_requests`, `hr_leave_balances` |
| Performance & supervision | `hr_supervision_notes`, `hr_performance_reviews`, `hr_probation_reviews` |
| HR cases & disciplinary | `hr_cases`, `hr_case_events`, `hr_disciplinary_actions` |
| Policy library | `hr_policies`, `hr_policy_versions`, `hr_policy_attestations` |
| Onboarding/offboarding | `hr_onboarding_checklists`, `hr_onboarding_tasks` |
| HR templates | `hr_document_templates` |
| HR documents | `hr_documents` (restricted access) |
| Issued assets register | References `asset_assignments` (Asset module is source of asset truth) |

### HR REFERENCES (does not own)

| Domain | Referenced From | How |
|---|---|---|
| Auth user account | `users` table | `user_id` FK on employee profile |
| RBAC roles | `roles`, `role_user` | Read roles to drive compliance matrix |
| Location membership | `sites` | `primary_site_id`, `secondary_site_ids` on employee profile |
| Rosters | `shifts`, `shift_series` | Read-only for eligibility checks; HR does not create shifts |
| Timesheets | `timesheets` | Read for payroll export; HR does not edit timesheets |
| Incidents | `client_incidents` | Link staff incidents to HR cases |
| Asset issuance | `asset_assignments` | Read issued assets list; Asset module manages the asset |
| Control Room alerts | `control_room_alerts` | Surface staff-related alerts |

---

## 3) RECRUITMENT PIPELINE

### Candidate Lifecycle State Machine

```
website_submission → screening → shortlisted → interview_scheduled
    → interviewed → reference_check → offer_pending → offer_sent
    → offer_accepted → converting → employed

At any stage: → withdrawn | rejected | on_hold
```

### Entity Models

#### `hr_candidates`
```
id, tenant_id
first_name, last_name, preferred_name
personal_email, personal_phone
source (website|referral|agency|internal|other)
source_detail (referrer name, agency name, etc.)
status (enum: see state machine above)
current_stage_entered_at
privacy_consent_given_at, privacy_consent_ip
notes (text, nullable)
tags (json: array of screening tags)
created_by, updated_by
created_at, updated_at, deleted_at
INDEX: [tenant_id, status], [personal_email]
```

#### `hr_applications`
```
id, tenant_id, candidate_id (FK)
position_title, position_role (maps to RBAC role)
target_site_id (FK → sites, nullable)
cv_storage_path, cv_original_name
cover_letter (text, nullable)
answers (json: screening question responses)
status (active|withdrawn|rejected|hired)
rejection_reason (text, nullable)
created_at, updated_at
INDEX: [candidate_id, status], [tenant_id, position_role]
```

#### `hr_interviews`
```
id, application_id (FK)
scheduled_at (datetime)
duration_minutes (default: 60)
location (string: room/video link)
interview_type (phone|video|in_person|panel)
interviewers (json: array of user_ids)
status (scheduled|completed|cancelled|no_show)
notes (text, nullable — interviewer notes)
rating (integer 1-5, nullable)
outcome (advance|hold|reject, nullable)
completed_by (FK → users, nullable)
created_at, updated_at
INDEX: [application_id, scheduled_at]
```

#### `hr_reference_checks`
```
id, application_id (FK)
referee_name, referee_email, referee_phone
referee_relationship (employer|colleague|character)
status (requested|received|verified|waived)
requested_at, received_at, verified_at
reference_notes (text, nullable — HR summary)
verified_by (FK → users, nullable)
created_at, updated_at
```

#### `hr_offers`
```
id, application_id (FK)
template_id (FK → hr_document_templates, nullable)
position_title, position_role
proposed_start_date
employment_type (full_time|part_time|casual|fixed_term)
hours_per_week (decimal)
hourly_rate (decimal, nullable)
annual_salary (decimal, nullable)
primary_site_id (FK → sites)
conditions (text, nullable)
approval_status (draft|pending_approval|approved|rejected)
approved_by (FK → users, nullable), approved_at
sent_at (datetime, nullable)
response (accepted|declined|negotiating, nullable)
response_at (datetime, nullable)
response_notes (text, nullable)
work_email_provisioned (boolean, default: false)
work_email (string, nullable)
created_by, updated_by
created_at, updated_at
INDEX: [application_id], [approval_status]
```

### Document Handling Rules
- CV and cover letters stored in `private` disk under `hr/candidates/{candidate_id}/`
- Access restricted to users with `hr.recruitment.view` permission
- Retention: configurable (default 24 months for unsuccessful candidates per Privacy Act)
- Successful candidates: documents migrate to employee HR file

### Privacy Notice (at submission)
```
We collect your personal information to assess your application for employment.
Your information will be held securely and accessed only by authorised HR personnel.
Under the Privacy Act 2020, you have the right to access and correct your information.
Unsuccessful candidate data is retained for [configurable] months, then securely destroyed.
```

### Conversion: Candidate → Employee
1. Offer status = `accepted`
2. System creates `hr_employee_profiles` record from offer data
3. System creates/links `users` record (if not internal candidate)
4. Work email provisioned (manual or integration point)
5. `hr_offers.work_email_provisioned` = true
6. Onboarding checklist auto-generated from compliance matrix for the role
7. ALL subsequent HR communications sent to work email only
8. Candidate record archived with `status = employed`

---

## 4) EMPLOYEE PROFILES

### `hr_employee_profiles`
```
id, tenant_id
user_id (FK → users, unique)
employee_number (string, unique, auto-generated)

-- Personal (HR-only access)
date_of_birth (date, encrypted at rest)
gender (string, nullable)
ethnicity (string, nullable — for optional NZ workforce reporting)
personal_email (string, nullable)
personal_phone (string, nullable)
home_address (text, encrypted at rest)

-- Employment
work_email (string)
work_phone (string, nullable)
position_title (string)
position_role (string — maps to RBAC role name)
employment_type (full_time|part_time|casual|fixed_term|contractor)
contract_type (individual|collective)
hours_per_week (decimal, nullable)
hourly_rate (decimal, nullable — encrypted)
annual_salary (decimal, nullable — encrypted)
pay_frequency (weekly|fortnightly|monthly)
start_date (date)
end_date (date, nullable)
probation_end_date (date, nullable)
termination_reason (string, nullable)
is_active (boolean, default: true)

-- Location
primary_site_id (FK → sites)
secondary_site_ids (json: array of site_ids)

-- Emergency Contacts
emergency_contacts (json: [{name, relationship, phone, email}])

-- Bank/Tax (encrypted, HR-only)
bank_account (string, encrypted, nullable)
ird_number (string, encrypted, nullable)
tax_code (string, nullable)
kiwisaver_rate (decimal, nullable)

-- Flags
can_drive_clients (boolean, default: false)
driver_eligibility_reviewed_at (datetime, nullable)
is_first_aider (boolean, default: false)
is_fire_warden (boolean, default: false)

-- Metadata
offer_id (FK → hr_offers, nullable)
candidate_id (FK → hr_candidates, nullable)
notes (text, nullable — general HR notes)
restricted_notes (text, nullable — HR-only sensitive notes, extra access logging)
created_by, updated_by
created_at, updated_at, deleted_at

INDEX: [tenant_id, is_active], [user_id], [employee_number], [primary_site_id]
```

### Versioning
Key fields that trigger version snapshots (stored in `hr_employee_profile_versions`):
- position_title, position_role, employment_type, hours_per_week, hourly_rate, annual_salary, primary_site_id

```
hr_employee_profile_versions
id, employee_profile_id (FK)
field_name, old_value, new_value
changed_by (FK → users)
reason (text, nullable)
effective_from (date)
created_at
```

### Privacy Classification
| Field Group | Access Level | Audit |
|---|---|---|
| Basic employment (name, title, site) | All managers at same site | Standard |
| Personal details (DOB, address, phone) | HR Admin, HR Officer | Enhanced (view logged) |
| Financial (bank, IRD, salary) | HR Admin, Payroll | Enhanced (view logged) |
| Restricted notes | HR Admin only | Enhanced (every view logged) |
| Vetting results | HR Admin, HR Officer | Enhanced (every view logged) |

---

## 5) ONBOARDING & OFFBOARDING

### Onboarding Checklist Architecture

#### `hr_onboarding_checklists`
```
id, tenant_id
employee_profile_id (FK)
template_key (string — references checklist template)
status (not_started|in_progress|completed|overdue)
started_at, completed_at
due_date (date)
created_by
created_at, updated_at
```

#### `hr_onboarding_tasks`
```
id, checklist_id (FK)
category (hr|training|it|access|assets|site)
title (string)
description (text, nullable)
is_required (boolean, default: true)
sort_order (integer)
assigned_to_user_id (FK → users, nullable)
assigned_to_role (string, nullable — e.g., 'hr', 'it_admin')
status (pending|in_progress|completed|skipped|blocked)
completed_at (datetime, nullable)
completed_by (FK → users, nullable)
evidence_path (string, nullable — uploaded proof)
sign_off_required (boolean, default: false)
signed_off_by (FK → users, nullable)
signed_off_at (datetime, nullable)
notes (text, nullable)
created_at, updated_at
INDEX: [checklist_id, category, sort_order]
```

### Default Onboarding Categories

**HR Tasks:**
- Employment contract signed
- Tax/IRD information captured
- Bank details captured (secure)
- Emergency contacts provided
- Privacy policy acknowledged
- Code of conduct acknowledged
- All required policy attestations completed

**Training Tasks:**
- Induction completed
- Medication procedures training (if role requires)
- Privacy & confidentiality training
- Health & safety orientation
- Incident reporting training
- Restrictive practices training (if role requires)
- First aid certificate verified (if role requires)

**IT Tasks:**
- Work email account created
- Platform account activated
- MFA configured
- Device issued (if applicable)

**Access Tasks:**
- Door access card issued
- Access groups configured per location
- Building alarm code provided

**Assets Tasks:**
- Phone issued (link to AssetAssignment)
- Keys issued (link to AssetAssignment)
- Uniform issued (link to AssetAssignment)
- Vehicle access configured (if applicable)

**Site Tasks:**
- Site orientation completed (per assigned site)
- Emergency procedures reviewed
- Meet the team introduction
- Buddy/mentor assigned

### Offboarding Checklist

#### `hr_offboarding_checklists`
Same structure as onboarding but with different default tasks:

**HR Tasks:**
- Final pay calculated and flagged
- Leave balance paid out or adjusted
- Exit interview conducted (optional)
- Reference availability confirmed
- Termination letter issued

**IT Tasks:**
- Email account disabled
- Platform access removed
- MFA removed
- Device collected

**Access Tasks:**
- Door access card recovered
- Access groups removed
- Building alarm code changed (if needed)

**Assets Tasks:**
- All issued assets returned (auto-populated from AssetAssignment where released_at IS NULL)
- Asset condition check completed
- Evidence photos uploaded

**Compliance Tasks:**
- Background check records archived
- Training records archived
- HR file retention policy applied

### Checklist Templates by Role
```
hr_onboarding_templates
id, tenant_id
role (string — RBAC role name, or 'all')
site_type (head_office|house|facility|all)
tasks (json: array of task definitions)
is_active (boolean)
created_by, updated_by
created_at, updated_at
```

---

## 6) ROLE-BASED COMPLIANCE MATRIX

### The Engine

This is the core innovation: a configurable matrix that maps roles to requirements, then evaluates each staff member's compliance status nightly and in real-time.

#### `hr_compliance_requirements`
```
id, tenant_id
code (string, unique — e.g., 'FIRST_AID_CERT', 'POLICE_VET', 'MED_COMPETENCY')
name (string)
description (text, nullable)
category (training|vetting|licence|competency|attestation)
check_type (credential|training_course|background_check|policy_attestation|manual)
reference_id (integer, nullable — FK to training_course_id or policy_id, depending on check_type)
validity_months (integer, nullable — null = lifetime)
renewal_reminder_days (integer, default: 60)
hard_stop (boolean, default: false) — if true, blocks rostering
is_active (boolean, default: true)
created_by, updated_by
created_at, updated_at
INDEX: [tenant_id, category, is_active]
```

#### `hr_compliance_matrix`
```
id, tenant_id
requirement_id (FK → hr_compliance_requirements)
role (string — RBAC role name)
site_type (string, nullable — head_office|house|facility|null=all)
is_mandatory (boolean, default: true)
notes (string, nullable)
created_at, updated_at
UNIQUE: [tenant_id, requirement_id, role, site_type]
```

#### `hr_staff_compliance_status`
```
id, tenant_id
user_id (FK → users)
requirement_id (FK → hr_compliance_requirements)
status (compliant|expiring_soon|expired|not_started|exempt)
evidence_type (credential|training_record|background_check|attestation|manual)
evidence_id (integer, nullable — polymorphic)
valid_from (date, nullable)
expires_at (date, nullable)
exemption_reason (text, nullable)
exempted_by (FK → users, nullable)
last_checked_at (datetime)
next_check_at (datetime)
created_at, updated_at
INDEX: [user_id, status], [tenant_id, status, expires_at], [requirement_id, status]
```

### Example Matrix (3 Roles)

```json
{
  "support_worker": [
    {"code": "POLICE_VET", "category": "vetting", "hard_stop": true, "validity_months": 36},
    {"code": "FIRST_AID", "category": "training", "hard_stop": false, "validity_months": 24},
    {"code": "MED_COMPETENCY", "category": "competency", "hard_stop": true, "validity_months": 12},
    {"code": "PRIVACY_TRAINING", "category": "training", "hard_stop": false, "validity_months": 12},
    {"code": "INCIDENT_REPORTING", "category": "training", "hard_stop": false, "validity_months": 24},
    {"code": "RESTRICTIVE_PRACTICES", "category": "training", "hard_stop": true, "validity_months": 12},
    {"code": "CODE_OF_CONDUCT", "category": "attestation", "hard_stop": false, "validity_months": 12},
    {"code": "HS_ORIENTATION", "category": "training", "hard_stop": false, "validity_months": null},
    {"code": "DRIVER_LICENCE", "category": "licence", "hard_stop": false, "validity_months": null}
  ],
  "team_lead": [
    "...all support_worker requirements plus...",
    {"code": "LEADERSHIP_TRAINING", "category": "training", "hard_stop": false, "validity_months": 24},
    {"code": "INCIDENT_INVESTIGATION", "category": "training", "hard_stop": false, "validity_months": 24},
    {"code": "PERFORMANCE_MGMT", "category": "training", "hard_stop": false, "validity_months": 24}
  ],
  "coordinator": [
    "...all team_lead requirements plus...",
    {"code": "ADVANCED_SAFEGUARDING", "category": "training", "hard_stop": false, "validity_months": 12}
  ]
}
```

### Evaluation Engine

**Nightly Job: `EvaluateComplianceMatrixJob`**
1. For each active employee:
   a. Determine applicable requirements from matrix (based on role + site_type)
   b. For each requirement, check evidence:
      - `training`: Look up latest valid `StaffTrainingRecord` for the linked course
      - `vetting`: Look up latest valid `StaffBackgroundCheck` for the check type
      - `credential`: Look up latest valid `StaffCredential` for the credential type
      - `attestation`: Look up latest `hr_policy_attestation` for the policy
      - `manual`: Check for manual override/sign-off
   c. Calculate status: compliant | expiring_soon | expired | not_started
   d. Upsert `hr_staff_compliance_status`
   e. Send notifications for status changes (expiring_soon, expired)

**Real-Time Check (at roster assignment):**
```php
class ComplianceMatrixService {
    public function canAssignToShift(User $user, Shift $shift): ComplianceCheckResult {
        $hardStopFailures = $this->getHardStopFailures($user);
        if ($hardStopFailures->isNotEmpty()) {
            return ComplianceCheckResult::blocked($hardStopFailures);
        }
        $warnings = $this->getSoftWarnings($user);
        return ComplianceCheckResult::allowed($warnings);
    }
}
```

---

## 7) TRAINING & COMPETENCY TRACKING

### Already Exists
- `TrainingCourse` — full catalog with renewal, prerequisites, assessment
- `StaffTrainingRecord` — enrollment, completion, expiry, certificates
- `CompetencyFramework` + `CompetencyItem` — role-based competency definitions
- `StaffCompetencyAssessment` — migration exists, needs model class

### What HR Adds
- **Dashboard views:** "Overdue by site", "Due next 30 days", filterable by role/site
- **Compliance matrix linkage:** Training courses linked to `hr_compliance_requirements`
- **Auto-enrollment:** When new employee starts or role changes, auto-enroll in mandatory courses
- **Rostering integration:** Warn/block if mandatory training expired (via compliance matrix hard_stop)
- **Board reporting feed:** Aggregate training stats for governance dashboards

### New: Create `StaffCompetencyAssessment` Model Class
```php
// app/Models/StaffCompetencyAssessment.php
// Matches existing migration: staff_competency_assessments table
// Relationships: user, competencyFramework, assessor, creator, updater
// Traits: SoftDeletes, AuditableChanges
```

---

## 8) POLICE VETTING / BACKGROUND CHECK REGISTER

### Already Exists (StaffBackgroundCheck)
- Full model with status workflow, risk assessment, document storage, renewal tracking
- Check types include: `police_check`, `dbs_*`, `reference_check`, `employment_history`, etc.
- Scopes: `active()`, `expired()`, `expiringSoon()`, `requiringAction()`

### What HR Adds

**NZ Police Vetting Specific Fields** (add to existing table):
```
nz_police_vetting_ref (string, nullable) — Police Vetting Service reference
consent_captured_at (datetime, nullable)
consent_method (enum: online_form|paper|email, nullable)
consent_document_path (string, nullable)
approved_agency_ref (string, nullable) — Approved agency reference number
```

**Consent Capture Workflow:**
1. HR initiates vetting request for employee
2. System generates consent form (from template)
3. Employee acknowledges consent (captured with timestamp, method, IP)
4. HR submits to NZ Police Vetting Service (manual — external portal)
5. Result received → HR enters status + any disclosures
6. If disclosures: risk assessment workflow (already exists in model)
7. Clearance recorded with expiry date

**Access Logging:**
- Every VIEW of vetting detail page logged to `audit_logs` with action `hr.vetting.viewed`
- Every DOWNLOAD of certificate logged
- Evidence pack generation strips restricted disclosure details unless viewer has `hr.vetting.view_disclosures`

**Approved Agency Assumptions:**
- Organisation is an approved agency under the Criminal Records (Clean Slate) Act 2004
- Online portal workflow is manual (HR logs into NZ Police Vetting portal externally)
- System tracks status and dates, not the actual vetting submission

---

## 9) DRIVER & VEHICLE ELIGIBILITY

### `hr_driver_eligibility`
```
id, tenant_id
user_id (FK → users, unique)
licence_number (string, nullable)
licence_class (string — e.g., 'Class 1', 'Class 2')
licence_endorsements (json: array of strings)
licence_expires_at (date, nullable)
licence_document_path (string, nullable)
can_drive_clients (boolean, default: false)
can_drive_clients_approved_by (FK → users, nullable)
can_drive_clients_approved_at (datetime, nullable)
incident_free_since (date, nullable) — date of last driving incident
last_reviewed_at (datetime, nullable)
next_review_at (datetime, nullable)
status (eligible|ineligible|review_required|suspended)
suspension_reason (text, nullable)
notes (text, nullable)
created_by, updated_by
created_at, updated_at
INDEX: [user_id], [tenant_id, status], [licence_expires_at]
```

### Integration Points
- **Rostering:** Shifts tagged `driving_required = true` only assignable to `can_drive_clients = true` staff
- **Incidents:** Driving incidents auto-flag `incident_free_since` reset and `status = review_required`
- **Fleet Module (future):** Driver sessions link to eligibility record
- **Compliance Matrix:** `DRIVER_LICENCE` requirement checks `licence_expires_at`

---

## 10) ROSTER & AVAILABILITY INTEGRATION

### Data Contract: HR ↔ Rostering

**HR Provides to Rostering:**
```php
interface HrRosteringContract {
    // Check if user can be assigned to a shift
    public function checkEligibility(int $userId, ?int $siteId = null): EligibilityResult;

    // Get compliance warnings for a user
    public function getComplianceWarnings(int $userId): Collection;

    // Check if user can drive clients
    public function canDriveClients(int $userId): bool;

    // Get approved leave blocks for date range
    public function getApprovedLeave(int $userId, Carbon $from, Carbon $to): Collection;

    // Get fatigue status
    public function getFatigueStatus(int $userId, Carbon $date): FatigueResult;
}
```

**Rostering Calls HR Before Assignment:**
```php
// In ShiftController::assign() — add before saving:
$eligibility = app(HrRosteringContract::class)->checkEligibility($userId, $shift->site_id);
if ($eligibility->isBlocked()) {
    return back()->with('error', $eligibility->blockReason());
}
if ($eligibility->hasWarnings()) {
    // Show warnings but allow assignment
}
```

**Real-Time Eligibility Messages:**
- "Cannot assign: Police vetting expired (expired 2026-01-15)"
- "Cannot assign: Medication competency expired"
- "Warning: First aid certificate expires in 14 days"
- "Cannot assign: Approved leave 2026-02-15 to 2026-02-20"
- "Warning: Staff has worked 48 hours this week (fatigue threshold)"

### Fatigue Rules
```
hr_fatigue_rules (config-driven, stored in config/hr.php)
- max_hours_per_day: 12
- max_hours_per_week: 50 (warning at 40)
- min_rest_between_shifts_hours: 10
- max_consecutive_days: 7
- warning_threshold_weekly: 40
- block_threshold_weekly: 50 (configurable as hard/soft)
```

### Minimal Rostering UI Changes
1. Add coloured badge on shift assignment card showing compliance status (green/amber/red)
2. Show tooltip with specific warnings on hover
3. Block assign button when hard-stop failures exist (with explanation)
4. Show leave overlay on weekly roster view

---

## 11) LEAVE MANAGEMENT

### `hr_leave_requests`
```
id, tenant_id
user_id (FK → users)
leave_type (annual|sick|bereavement|parental|other)
starts_at (datetime)
ends_at (datetime)
hours_requested (decimal) — calculated or manual
reason (text, nullable)
supporting_doc_path (string, nullable — medical cert, etc.)
status (draft|pending|approved|declined|cancelled)
submitted_at (datetime, nullable)
reviewed_by (FK → users, nullable)
reviewed_at (datetime, nullable)
review_notes (text, nullable)
escalated_to (FK → users, nullable) — if team leader can't approve
time_off_id (FK → staff_time_offs, nullable) — linked availability block
created_by
created_at, updated_at
INDEX: [user_id, status], [tenant_id, status, starts_at], [user_id, leave_type]
```

### `hr_leave_balances`
```
id, tenant_id
user_id (FK → users)
leave_type (annual|sick|bereavement|parental|lieu|other)
balance_hours (decimal)
accrued_hours (decimal)
used_hours (decimal)
pending_hours (decimal) — hours in pending requests
year (integer — financial year)
source (payroll_sync|manual|system)
last_synced_at (datetime, nullable)
notes (text, nullable)
updated_by (FK → users, nullable)
created_at, updated_at
UNIQUE: [tenant_id, user_id, leave_type, year]
```

### Approval Workflow
1. Employee submits leave request
2. Team leader receives notification → approves/declines
3. If declined, employee notified with reason
4. If approved:
   a. `StaffTimeOff` record auto-created (type = 'leave')
   b. `hr_leave_balances.pending_hours` reduced, `used_hours` increased
   c. Rostering conflicts flagged (shift overlaps show warning)
5. HR can view all leave requests across org

### Rostering Conflict Resolution
When approved leave overlaps existing shift:
- Shift shows "Leave Conflict" badge in rostering view
- Team leader must reassign or cancel the shift
- System does NOT auto-cancel shifts (prevents unintended gaps)

---

## 12) TIMESHEETS & PAYROLL EXPORT

### Existing Timesheet Approval (Already Built)
The timesheet system already has: draft → submitted → approved/rejected/returned workflow with bulk operations.

### What HR Adds

**Allowance Fields** (add to existing `timesheets` table):
```
mileage_km (decimal, nullable)
sleepover (boolean, default: false)
on_call (boolean, default: false)
allowance_notes (text, nullable)
public_holiday (boolean, default: false)
```

**Payroll Export:**

#### `hr_payroll_runs`
```
id, tenant_id
period_start (date)
period_end (date)
status (draft|locked|exported|finalised)
locked_at (datetime, nullable)
locked_by (FK → users, nullable)
exported_at (datetime, nullable)
exported_by (FK → users, nullable)
export_format (csv|api)
export_path (string, nullable)
total_hours (decimal)
total_staff (integer)
notes (text, nullable)
created_by
created_at, updated_at
INDEX: [tenant_id, period_start, status]
```

#### `hr_payroll_run_items`
```
id, payroll_run_id (FK)
user_id (FK → users)
timesheet_ids (json: array of timesheet IDs included)
regular_hours (decimal)
overtime_hours (decimal)
sleepover_count (integer)
on_call_hours (decimal)
mileage_km (decimal)
public_holiday_hours (decimal)
gross_pay (decimal, nullable — if calculated)
allowances (json: {type: amount} pairs)
notes (text, nullable)
created_at, updated_at
```

### Approval Chain for Timesheets
1. **Staff submits** timesheet (existing)
2. **Team leader reviews/approves** (existing — primary approver)
3. **HR/Payroll locks** pay period (new — prevents further edits)
4. **Export** generated (CSV or API placeholder)
5. Locked timesheets are immutable

### Export Format (CSV)
```csv
employee_number,name,work_date,regular_hours,overtime_hours,sleepover,on_call_hours,mileage_km,public_holiday,allowances,notes
EMP001,Jane Smith,2026-02-01,8.00,0.00,0,0.00,25.5,0,"",""
```

---

## 13) INCIDENT & HR CASE MANAGEMENT

### Extending Existing Incidents

**Add to `client_incidents` table:**
```
is_hr_confidential (boolean, default: false)
hr_case_id (FK → hr_cases, nullable)
```

When `is_hr_confidential = true`:
- Only users with `hr.cases.view` can see the incident
- Incident hidden from standard incident lists for non-HR users
- Audit log captures every view

### HR Cases

#### `hr_cases`
```
id, tenant_id
case_number (string, unique, auto-generated — e.g., 'HR-2026-001')
user_id (FK → users — the subject employee)
case_type (misconduct|complaint|grievance|performance|workplace_injury|
           harassment|bullying|theft|policy_breach|other)
severity (low|medium|high|critical)
status (open|investigation|meeting_scheduled|awaiting_response|
        resolved|closed|withdrawn)
title (string)
description (text)
reported_by (FK → users, nullable)
assigned_to (FK → users — HR officer handling)
opened_at (datetime)
closed_at (datetime, nullable)
outcome (text, nullable)
outcome_type (no_action|coaching|verbal_warning|written_warning|
              final_warning|termination|mediation|other, nullable)
is_confidential (boolean, default: true)
access_list (json: array of user_ids who can view)
linked_incident_ids (json: array of client_incident IDs)
created_by, updated_by
created_at, updated_at, deleted_at
INDEX: [tenant_id, status], [user_id, status], [case_number]
```

#### `hr_case_events`
```
id, case_id (FK)
event_type (note|meeting|document|decision|communication|status_change)
title (string)
description (text, nullable)
occurred_at (datetime)
document_path (string, nullable)
visibility (hr_only|manager|all_parties)
created_by
created_at, updated_at
INDEX: [case_id, occurred_at]
```

---

## 14) PERFORMANCE & SUPERVISION NOTES

### `hr_supervision_notes`
```
id, tenant_id
employee_user_id (FK → users)
supervisor_user_id (FK → users)
session_date (date)
session_type (one_on_one|supervision|check_in|informal)
duration_minutes (integer, nullable)
topics_discussed (text)
actions_agreed (json: [{action, due_date, owner}])
employee_comments (text, nullable)
employee_acknowledged (boolean, default: false)
employee_acknowledged_at (datetime, nullable)
next_session_date (date, nullable)
is_visible_to_employee (boolean, default: true)
created_by
created_at, updated_at
INDEX: [employee_user_id, session_date], [supervisor_user_id, session_date]
```

### `hr_performance_reviews`
```
id, tenant_id
employee_user_id (FK → users)
reviewer_user_id (FK → users)
review_type (probation|quarterly|annual|ad_hoc)
review_period_start (date)
review_period_end (date)
status (draft|self_assessment|manager_review|meeting|completed)
overall_rating (integer 1-5, nullable)
strengths (text, nullable)
development_areas (text, nullable)
goals (json: [{goal, measure, target_date, status}])
training_recommendations (json: array of training_course_ids)
employee_comments (text, nullable)
employee_signed_off (boolean, default: false)
employee_signed_off_at (datetime, nullable)
manager_signed_off (boolean, default: false)
manager_signed_off_at (datetime, nullable)
next_review_date (date, nullable)
created_by, updated_by
created_at, updated_at
INDEX: [employee_user_id, review_type], [tenant_id, status]
```

### `hr_probation_reviews`
```
id, tenant_id
employee_user_id (FK → users)
reviewer_user_id (FK → users)
review_number (integer — 1st, 2nd, 3rd month review)
review_date (date)
status (scheduled|completed)
areas_assessed (json: [{area, rating, notes}])
concerns (text, nullable)
recommendation (pass|extend|fail)
extension_weeks (integer, nullable)
notes (text, nullable)
employee_acknowledged (boolean, default: false)
employee_acknowledged_at (datetime, nullable)
created_by
created_at, updated_at
INDEX: [employee_user_id, review_date]
```

---

## 15) DISCIPLINARY WORKFLOW

### Process Stages State Machine
```
investigation → notice_issued → meeting_scheduled → meeting_held
    → response_period → outcome_decided → outcome_communicated → closed

At any stage: → withdrawn | no_further_action
```

### `hr_disciplinary_actions`
```
id, tenant_id
case_id (FK → hr_cases)
employee_user_id (FK → users)
stage (enum: see state machine above)
action_type (investigation|verbal_warning|first_written_warning|
             final_written_warning|termination|other)

-- Investigation
allegation_summary (text)
investigation_notes (text, nullable)
investigator_user_id (FK → users, nullable)

-- Notice
notice_issued_at (datetime, nullable)
notice_document_path (string, nullable)
meeting_scheduled_at (datetime, nullable)
meeting_location (string, nullable)
support_person_advised (boolean, default: false)

-- Meeting
meeting_held_at (datetime, nullable)
meeting_notes (text, nullable)
meeting_attendees (json: [{user_id, role}])
employee_response (text, nullable)
response_deadline (datetime, nullable)

-- Outcome
outcome (no_action|coaching|verbal_warning|written_warning|
         final_warning|termination|other, nullable)
outcome_decided_at (datetime, nullable)
outcome_decided_by (FK → users, nullable)
outcome_rationale (text, nullable)
outcome_communicated_at (datetime, nullable)
outcome_document_path (string, nullable)

-- Good Faith Checklist
good_faith_checklist (json: {
    "allegations_clear": bool,
    "evidence_provided": bool,
    "reasonable_time_to_respond": bool,
    "support_person_offered": bool,
    "genuinely_considered_response": bool,
    "proportionate_outcome": bool,
    "right_of_appeal_advised": bool
})

-- Appeal
appeal_received (boolean, default: false)
appeal_received_at (datetime, nullable)
appeal_notes (text, nullable)
appeal_outcome (upheld|modified|overturned, nullable)

created_by, updated_by
created_at, updated_at
INDEX: [case_id], [employee_user_id, stage]
```

---

## 16) WELLBEING & EAP

### Dashboard Indicators (Non-Medical, Aggregate Only)

#### `hr_wellbeing_indicators` (materialised nightly)
```
id, tenant_id
user_id (FK → users)
period_start (date), period_end (date)
overtime_hours (decimal)
consecutive_days_worked (integer)
sick_leave_days_30d (integer)
sick_leave_days_90d (integer)
shifts_worked_7d (integer)
average_shift_length_hours (decimal)
flag_level (none|monitor|concern) — algorithmic, not diagnostic
calculated_at (datetime)
INDEX: [tenant_id, flag_level], [user_id, period_end]
```

**Flag Rules (configurable):**
- `monitor`: overtime > 10h/week OR consecutive_days > 5 OR sick_leave_90d > 6
- `concern`: overtime > 20h/week OR consecutive_days > 7 OR sick_leave_90d > 10

**EAP Resource Hub:**
- Static page with configured EAP provider details
- Links to external support services
- Accessible by all staff via "My HR" self-service

---

## 17) POLICY LIBRARY & ATTESTATIONS

### `hr_policies`
```
id, tenant_id
title (string)
slug (string, unique)
category (employment|health_safety|privacy|conduct|clinical|operational|other)
is_active (boolean, default: true)
requires_attestation (boolean, default: true)
attestation_frequency_months (integer, nullable — null = one-time)
created_by, updated_by
created_at, updated_at
INDEX: [tenant_id, category, is_active]
```

### `hr_policy_versions`
```
id, policy_id (FK)
version_number (integer)
content_summary (text) — what changed
document_path (string)
effective_from (date)
is_current (boolean, default: true)
published_by (FK → users)
created_at
INDEX: [policy_id, is_current]
```

### `hr_policy_attestations`
```
id, tenant_id
user_id (FK → users)
policy_id (FK → hr_policies)
policy_version_id (FK → hr_policy_versions)
attested_at (datetime)
ip_address (string, nullable)
user_agent (string, nullable)
attestation_method (web|mobile|paper)
created_at
INDEX: [user_id, policy_id], [tenant_id, policy_id]
```

### Integration with Compliance Matrix
- `hr_compliance_requirements` with `check_type = 'attestation'` and `reference_id = policy_id`
- Matrix evaluation checks `hr_policy_attestations` for current version acknowledgement

---

## 18) ASSETS ISSUED TO STAFF

### Already Exists
`AssetAssignment` table with polymorphic `assignee_type` (staff|client|whanau), `assigned_at`, `released_at`, `purpose`.

### HR Integration
- Employee profile "Issued Assets" tab reads `asset_assignments WHERE assignee_type = 'staff' AND assignee_id = user_id AND released_at IS NULL`
- Onboarding checklist auto-populates "Issue phone/keys/card" tasks
- Offboarding checklist auto-populates "Recover" tasks from active assignments
- Condition check: add `condition_on_issue` and `condition_on_return` to asset_assignments
- Photo evidence: link to AssetDocument

**No new tables needed** — HR reads from existing Asset module tables.

---

## 19) HR DOCUMENT TEMPLATES

### `hr_document_templates`
```
id, tenant_id
name (string)
category (contract|variation|letter|reference|warning|termination|other)
content (text — with merge fields like {{employee_name}}, {{position_title}}, etc.)
merge_fields (json: array of available field names)
is_active (boolean, default: true)
version (integer, default: 1)
approval_required (boolean, default: false)
created_by, updated_by
created_at, updated_at
INDEX: [tenant_id, category, is_active]
```

### `hr_documents`
```
id, tenant_id
employee_profile_id (FK → hr_employee_profiles)
template_id (FK → hr_document_templates, nullable)
title (string)
category (contract|variation|letter|reference|warning|payslip|certificate|other)
storage_disk (string, default: 'local')
storage_path (string)
original_name (string)
mime_type (string, nullable)
size_bytes (integer, nullable)
is_restricted (boolean, default: false) — HR-only access
generated_from_template (boolean, default: false)
sent_to_employee (boolean, default: false)
sent_at (datetime, nullable)
signed_by_employee (boolean, default: false)
signed_at (datetime, nullable)
signed_document_path (string, nullable)
created_by, uploaded_by
created_at, updated_at
INDEX: [employee_profile_id, category], [tenant_id, category]
```

### Merge Fields
```php
$mergeFields = [
    'employee_name', 'employee_number', 'position_title',
    'position_role', 'employment_type', 'start_date', 'end_date',
    'hours_per_week', 'hourly_rate', 'annual_salary',
    'primary_site_name', 'manager_name',
    'today_date', 'organisation_name',
];
```

---

## 20) PRIVACY & ACCESS CONTROLS

### RBAC Roles for HR

| Role | Access Scope |
|---|---|
| `hr_admin` | Full HR access including restricted notes, financials, vetting disclosures |
| `hr_officer` | HR access excluding salary details and restricted notes |
| `manager` / `team_lead` | View profiles of direct reports; approve leave/timesheets; supervision notes |
| `payroll` | View employee financials, timesheet exports, leave balances |
| `employee` (self-service) | Own profile basics, availability, leave requests, policy attestations, training status |
| `auditor` | Read-only access to HR reports and compliance data (no personal details) |

### New Permissions (add to RbacSeeder)
```
hr.recruitment.view, hr.recruitment.manage
hr.employees.viewAny, hr.employees.viewOwn, hr.employees.manage
hr.employees.viewFinancial, hr.employees.viewRestricted
hr.compliance.view, hr.compliance.manage
hr.training.view, hr.training.manage
hr.vetting.view, hr.vetting.manage, hr.vetting.view_disclosures
hr.leave.viewAny, hr.leave.viewOwn, hr.leave.approve, hr.leave.manage
hr.performance.view, hr.performance.manage
hr.cases.view, hr.cases.manage
hr.disciplinary.view, hr.disciplinary.manage
hr.policies.view, hr.policies.manage, hr.policies.attest
hr.documents.view, hr.documents.manage
hr.payroll.view, hr.payroll.export
hr.reports.view, hr.reports.export
hr.driver.view, hr.driver.manage
hr.wellbeing.view
hr.onboarding.view, hr.onboarding.manage
```

### Location Scoping
- Managers see only employees at their assigned sites (unless `hr.employees.viewAny`)
- HR Admin/Officer see all employees regardless of site
- Site-scoped queries use `hr_employee_profiles.primary_site_id` and `secondary_site_ids`

### Audit Logging for Sensitive Data
- Every view of: vetting details, salary, restricted notes, financial details → `audit_logs` with action prefix `hr.sensitive.`
- Every download of HR document → logged
- Every export of payroll data → logged with user, timestamp, IP
- Configurable data retention: default 7 years for employee records, 24 months for unsuccessful candidates

---

## 21) AUDIT & COMPLIANCE REPORTS

### Standard Reports

| Report | Description | Filters |
|---|---|---|
| Overdue Training | Staff with expired/overdue mandatory training | Site, Role, Course |
| Vetting Due | Background checks due for renewal in 30/60/90 days | Site, Role |
| Driver Eligibility Expiring | Licences expiring within configurable window | Site |
| Policy Attestations Outstanding | Staff who haven't acknowledged current policy versions | Policy, Site, Role |
| Training by Site/Role | Training completion rates aggregated | Site, Role, Period |
| Compliance Matrix Status | Per-employee compliance status dashboard | Site, Role, Status |
| Timesheet Approval Backlog | Submitted timesheets awaiting approval | Site, Approver |
| Leave Balances | Current leave balances by type | Site, Leave Type |
| Onboarding Progress | New starters with incomplete onboarding | Site, Status |
| Wellbeing Indicators | Aggregated overtime/fatigue flags (no individual medical data) | Site, Flag Level |
| Headcount | Active employees by site, role, employment type | Site, Role, Type |
| Turnover | Starters/leavers by period | Period, Site |

### Evidence Packs
```php
class HrEvidencePackService {
    public function generate(
        string $packType, // 'compliance_audit', 'nga_paerewa', 'site_audit'
        ?int $siteId,
        Carbon $periodStart,
        Carbon $periodEnd,
        User $generatedBy
    ): array {
        // Returns manifest with:
        // - Training completion records (redacted where needed)
        // - Vetting status summary (no disclosure details unless permitted)
        // - Policy attestation records
        // - Compliance matrix snapshot
        // - Onboarding completion rates
        // - Leave and overtime summaries
    }
}
```

---

## 22) UI / NAVIGATION

### Top-Level HR Nav (max 6 items)
```
HR (sidebar group, gated by hr.* permissions)
├── Recruitment      → /hr/recruitment
├── People           → /hr/people
├── Compliance       → /hr/compliance
├── Leave & Rosters  → /hr/leave (with links to /rostering)
├── Performance      → /hr/performance
└── Reports          → /hr/reports
```

### "My HR" Self-Service Panel (all employees)
Accessible from user menu or `/hr/my`:
- **My Profile**: View/edit basics (name, phone, emergency contacts)
- **My Availability**: Manage availability patterns + time off
- **My Leave**: Submit/track leave requests, view balances
- **My Training**: View training status, upcoming courses, certificates
- **My Policies**: View required policies, complete attestations
- **My Compliance**: View personal compliance matrix status

### Key Pages

| Page | Path | Permission |
|---|---|---|
| Recruitment Pipeline | `/hr/recruitment` | `hr.recruitment.view` |
| Candidate Detail | `/hr/recruitment/candidates/{id}` | `hr.recruitment.view` |
| People List | `/hr/people` | `hr.employees.viewAny` |
| Employee Profile | `/hr/people/{id}` | `hr.employees.viewAny` or `viewOwn` |
| Compliance Dashboard | `/hr/compliance` | `hr.compliance.view` |
| Compliance Matrix Config | `/hr/compliance/matrix` | `hr.compliance.manage` |
| Training Dashboard | `/hr/compliance/training` | `hr.training.view` |
| Vetting Register | `/hr/compliance/vetting` | `hr.vetting.view` |
| Driver Register | `/hr/compliance/drivers` | `hr.driver.view` |
| Leave Requests | `/hr/leave` | `hr.leave.viewAny` |
| My Leave | `/hr/my/leave` | `hr.leave.viewOwn` |
| Performance Reviews | `/hr/performance` | `hr.performance.view` |
| HR Cases | `/hr/performance/cases` | `hr.cases.view` |
| Policy Library | `/hr/policies` | `hr.policies.view` |
| Reports | `/hr/reports` | `hr.reports.view` |
| My HR | `/hr/my` | Any authenticated user |

---

## 23) LARAVEL IMPLEMENTATION PLAN

### Module Structure
```
app/
├── Domain/
│   └── Hr/
│       ├── Models/
│       │   ├── HrCandidate.php
│       │   ├── HrApplication.php
│       │   ├── HrInterview.php
│       │   ├── HrReferenceCheck.php
│       │   ├── HrOffer.php
│       │   ├── HrEmployeeProfile.php
│       │   ├── HrEmployeeProfileVersion.php
│       │   ├── HrComplianceRequirement.php
│       │   ├── HrComplianceMatrix.php
│       │   ├── HrStaffComplianceStatus.php
│       │   ├── HrDriverEligibility.php
│       │   ├── HrLeaveRequest.php
│       │   ├── HrLeaveBalance.php
│       │   ├── HrOnboardingChecklist.php
│       │   ├── HrOnboardingTask.php
│       │   ├── HrOffboardingChecklist.php
│       │   ├── HrSupervisionNote.php
│       │   ├── HrPerformanceReview.php
│       │   ├── HrProbationReview.php
│       │   ├── HrCase.php
│       │   ├── HrCaseEvent.php
│       │   ├── HrDisciplinaryAction.php
│       │   ├── HrPolicy.php
│       │   ├── HrPolicyVersion.php
│       │   ├── HrPolicyAttestation.php
│       │   ├── HrDocumentTemplate.php
│       │   ├── HrDocument.php
│       │   ├── HrPayrollRun.php
│       │   ├── HrPayrollRunItem.php
│       │   ├── HrWellbeingIndicator.php
│       │   └── HrOnboardingTemplate.php
│       ├── Services/
│       │   ├── ComplianceMatrixService.php
│       │   ├── RecruitmentService.php
│       │   ├── OnboardingService.php
│       │   ├── LeaveService.php
│       │   ├── PayrollExportService.php
│       │   ├── HrEvidencePackService.php
│       │   ├── HrDocumentMergeService.php
│       │   ├── WellbeingIndicatorService.php
│       │   └── HrRosteringContract.php (interface)
│       ├── Jobs/
│       │   ├── EvaluateComplianceMatrixJob.php
│       │   ├── SendExpiryRemindersJob.php
│       │   ├── CalculateWellbeingIndicatorsJob.php
│       │   ├── ProcessLeaveBalanceAccrualJob.php
│       │   └── ArchiveCandidateDataJob.php
│       └── Notifications/
│           ├── ComplianceExpiryNotification.php
│           ├── LeaveRequestNotification.php
│           ├── LeaveApprovedNotification.php
│           ├── OnboardingTaskAssignedNotification.php
│           ├── PolicyAttestationDueNotification.php
│           ├── PerformanceReviewDueNotification.php
│           └── HrCaseUpdateNotification.php
├── Http/
│   └── Controllers/
│       └── Hr/
│           ├── RecruitmentController.php
│           ├── CandidateController.php
│           ├── EmployeeProfileController.php
│           ├── ComplianceController.php
│           ├── ComplianceMatrixController.php
│           ├── TrainingDashboardController.php
│           ├── VettingController.php
│           ├── DriverEligibilityController.php
│           ├── LeaveController.php
│           ├── OnboardingController.php
│           ├── SupervisionController.php
│           ├── PerformanceReviewController.php
│           ├── HrCaseController.php
│           ├── DisciplinaryController.php
│           ├── PolicyController.php
│           ├── PolicyAttestationController.php
│           ├── HrDocumentController.php
│           ├── PayrollExportController.php
│           ├── HrReportController.php
│           └── MyHrController.php
```

### Migrations Outline (in order)

| # | Migration | Tables | Key Indexes |
|---|---|---|---|
| 1 | `create_hr_recruitment_tables` | hr_candidates, hr_applications, hr_interviews, hr_reference_checks, hr_offers | candidate(tenant_id, status), application(candidate_id), offer(application_id, approval_status) |
| 2 | `create_hr_employee_profiles_tables` | hr_employee_profiles, hr_employee_profile_versions | profile(tenant_id, is_active, user_id, employee_number, primary_site_id) |
| 3 | `create_hr_compliance_matrix_tables` | hr_compliance_requirements, hr_compliance_matrix, hr_staff_compliance_status | requirement(tenant_id, category), matrix(requirement_id, role), status(user_id, status) |
| 4 | `create_hr_leave_tables` | hr_leave_requests, hr_leave_balances | request(user_id, status, starts_at), balance(user_id, leave_type, year) |
| 5 | `create_hr_onboarding_tables` | hr_onboarding_templates, hr_onboarding_checklists, hr_onboarding_tasks, hr_offboarding_checklists | checklist(employee_profile_id, status), task(checklist_id, category) |
| 6 | `create_hr_performance_tables` | hr_supervision_notes, hr_performance_reviews, hr_probation_reviews | supervision(employee_user_id, session_date), review(employee_user_id, review_type) |
| 7 | `create_hr_cases_tables` | hr_cases, hr_case_events, hr_disciplinary_actions | case(tenant_id, status, user_id, case_number), disciplinary(case_id, employee_user_id) |
| 8 | `create_hr_policy_tables` | hr_policies, hr_policy_versions, hr_policy_attestations | policy(tenant_id, category), attestation(user_id, policy_id) |
| 9 | `create_hr_documents_tables` | hr_document_templates, hr_documents | document(employee_profile_id, category) |
| 10 | `create_hr_payroll_tables` | hr_payroll_runs, hr_payroll_run_items | run(tenant_id, period_start, status) |
| 11 | `create_hr_driver_eligibility_table` | hr_driver_eligibility | eligibility(user_id, tenant_id, status) |
| 12 | `create_hr_wellbeing_indicators_table` | hr_wellbeing_indicators | indicator(tenant_id, flag_level, user_id) |
| 13 | `add_hr_fields_to_existing_tables` | ALTER timesheets (add allowance fields), ALTER client_incidents (add is_hr_confidential, hr_case_id), ALTER staff_background_checks (add NZ vetting fields) | |

### Queue Jobs

| Job | Schedule | Purpose |
|---|---|---|
| `EvaluateComplianceMatrixJob` | Daily 01:00 | Evaluate all staff compliance status |
| `SendExpiryRemindersJob` | Daily 08:00 | Send reminders for expiring credentials/training/vetting |
| `CalculateWellbeingIndicatorsJob` | Daily 02:00 | Calculate overtime/fatigue indicators |
| `ProcessLeaveBalanceAccrualJob` | Monthly 1st | Accrue leave balances (or sync from payroll) |
| `ArchiveCandidateDataJob` | Weekly | Archive/purge expired candidate data per retention policy |

### Events Emitted

| Event | Consumed By |
|---|---|
| `HrEmployeeCreated` | Onboarding service (auto-generate checklist) |
| `HrEmployeeRoleChanged` | Compliance matrix (re-evaluate requirements) |
| `HrLeaveApproved` | Rostering (create StaffTimeOff block) |
| `HrLeaveCancelled` | Rostering (remove StaffTimeOff block) |
| `HrComplianceStatusChanged` | Notifications (send alerts for expiry) |
| `HrCaseOpened` | Notifications (alert HR admin) |
| `HrPolicyPublished` | Compliance matrix (trigger attestation requirements) |
| `HrPayrollRunLocked` | Timesheets (prevent further edits) |

### Notification Templates

| Notification | Channels | Recipients |
|---|---|---|
| `ComplianceExpiryNotification` | database, email | Employee + their manager |
| `LeaveRequestNotification` | database, email | Approver (team leader) |
| `LeaveApprovedNotification` | database, email | Employee |
| `OnboardingTaskAssignedNotification` | database, email | Task assignee |
| `PolicyAttestationDueNotification` | database, email | Employee |
| `PerformanceReviewDueNotification` | database | Employee + reviewer |
| `HrCaseUpdateNotification` | database | HR officer + case parties |

### Testing Plan
- **Unit tests:** ComplianceMatrixService, LeaveService, PayrollExportService, OnboardingService
- **Feature tests:** Full workflow tests for recruitment pipeline, leave approval, onboarding completion
- **Seed data:** 20 employees across 3 sites with varied compliance statuses, 5 candidates at different stages
- **Edge case seeds:** Expired vetting, overdue training, leave conflicts, multi-site staff

### Observability
- All HR models use `AuditableChanges` trait
- Sensitive field access logged via `AuditLogger::log('hr.sensitive.*', ...)`
- Failed compliance checks logged as `hr.compliance.check_failed`
- Payroll exports logged as `hr.payroll.exported`

---

## 24) FAILURE MODES & EDGE CASES (25+)

| # | Scenario | Detection | Resolution |
|---|---|---|---|
| 1 | Candidate converted to employee without required approvals | Offer.approval_status != 'approved' when conversion attempted | Block conversion until offer is approved; validate in ConversionService |
| 2 | Work email not created but onboarding started | hr_offers.work_email_provisioned = false when onboarding checklist generated | Onboarding task "Create work email" marked as blocker; subsequent tasks dependent |
| 3 | Vetting expired mid-roster (shift already assigned) | Nightly compliance check finds expired vetting with future shifts | Notification to team leader + HR; hard_stop = true removes from future unworked shifts |
| 4 | Leave approved but roster conflict persists | Approved leave overlaps scheduled shift | "Leave Conflict" badge on shift; team leader must reassign manually |
| 5 | Timesheet edited after payroll export locked | Timesheet.status change attempted on locked period | Payroll run lock prevents status changes; return 422 with explanation |
| 6 | Role change requiring new training not assigned | Employee role changed in RBAC | `HrEmployeeRoleChanged` event triggers compliance matrix re-evaluation; new requirements auto-added |
| 7 | Multi-site staff eligibility conflicts | Staff assigned to site A (compliant) and site B (missing site-specific training) | Per-site compliance status; shift assignment checks site_id match |
| 8 | Duplicate candidate submission (same email) | hr_candidates.personal_email unique check | Show existing candidate; offer to add new application to existing record |
| 9 | Offer accepted but candidate declines after onboarding starts | Offer response changed to 'declined' after conversion | Trigger offboarding workflow; deactivate user account; archive HR record |
| 10 | Training certificate uploaded but not linked to compliance requirement | Training record exists but hr_staff_compliance_status not updated | Nightly job re-evaluates; also evaluate on training record creation |
| 11 | Leave balance goes negative | Leave approved when balance insufficient | Configurable: allow negative with warning OR block; show balance in approval UI |
| 12 | Disciplinary action without good faith checklist completed | hr_disciplinary_actions.good_faith_checklist has false items | Soft warning on outcome screen; log as `hr.goodfaith.incomplete` |
| 13 | Manager tries to view restricted HR notes | Manager lacks `hr.employees.viewRestricted` | Permission check; return 403; log attempted access |
| 14 | Payroll export run twice for same period | Duplicate hr_payroll_runs for overlapping dates | Unique constraint on (tenant_id, period_start, period_end); block if existing non-draft run |
| 15 | Employee terminated but still has future shifts | hr_employee_profiles.end_date set but shifts exist after that date | Offboarding service flags future shifts for reassignment |
| 16 | Policy updated but staff not re-attested | New hr_policy_versions.is_current = true; old attestations now stale | Compliance matrix re-evaluation; send attestation reminders |
| 17 | Concurrent leave requests overlapping | Two requests for same dates submitted simultaneously | Check for overlapping pending/approved requests before approval |
| 18 | Background check flagged but employee continues working | StaffBackgroundCheck.risk_decision = 'declined' but is_active = true | Hard-stop in compliance matrix immediately blocks rostering |
| 19 | Onboarding checklist completed but mandatory items skipped | Tasks with is_required=true still pending when checklist marked complete | Validate all required tasks completed before allowing checklist completion |
| 20 | Driver eligibility suspended but shift is driving-required | hr_driver_eligibility.status = 'suspended' | Real-time check in shift assignment; block with message |
| 21 | Reference check received after offer already sent | hr_reference_checks status updated post-offer | Log warning; allow HR to review and potentially rescind offer |
| 22 | Staff member has no employee profile (legacy data) | User exists but no hr_employee_profiles record | Migration script to create profiles from existing StaffProfile data |
| 23 | Probation review overdue | hr_probation_reviews not created by probation_end_date - 14 days | Scheduled job checks approaching probation ends; sends reminders |
| 24 | Offboarding incomplete but account deactivated | User.is_active = false but hr_offboarding_checklist.status != 'completed' | Block account deactivation until critical offboarding tasks done (or HR override) |
| 25 | Compliance requirement deleted but staff still tracked against it | hr_compliance_requirements soft-deleted | Nightly job removes orphaned hr_staff_compliance_status records |
| 26 | Bulk import of training records doesn't trigger compliance update | Direct DB insert bypasses model events | Dispatch EvaluateComplianceMatrixJob after bulk import |
| 27 | Pay rate change mid-pay-period | hr_employee_profiles hourly_rate changed | Version snapshot captures effective_from; payroll export calculates pro-rata |

---

## 25) WHAT IS MISSING (DIRECT)

### Missing Integrations
| Integration | Status | Minimum Decision |
|---|---|---|
| **Identity/Email Provisioning** | No system to auto-create work email accounts | Define: manual process vs Microsoft 365/Google Workspace API |
| **Payroll System Export Format** | No payroll system identified | Define: which payroll software (PayHero, iPayroll, Xero, SmartPayroll) and export format |
| **Website Recruitment Form** | No public-facing job application endpoint | Define: WordPress plugin, custom API endpoint, or third-party ATS |
| **Leave Balance Sync** | No payroll → leave balance sync | Define: payroll is source of truth OR HR module manages balances |

### Missing Policy Decisions
| Decision | Default Assumption | Must Confirm |
|---|---|---|
| **Police vetting cadence** | Every 3 years | Confirm with org policy |
| **Training catalog ownership** | HR manages catalog; L&D team if exists | Confirm who maintains courses |
| **Fatigue rules** | 50h/week hard cap, 10h min rest | Confirm with employment agreements |
| **Timesheet approval hierarchy** | Team leader → HR/Payroll lock | Confirm: is CEO approval needed for any? |
| **Leave approval authority** | Team leader approves; HR can override | Confirm delegation rules |
| **Candidate data retention** | 24 months for unsuccessful | Confirm per Privacy Act policy |
| **Employee data retention** | 7 years post-termination | Confirm per regulatory requirements |
| **Probation period** | 90 days | Confirm per employment agreements |
| **Salary visibility** | HR Admin + Payroll only | Confirm: can managers see direct report salary? |

### Missing Existing Module Capabilities
| Module | Gap | Impact |
|---|---|---|
| **Rostering** | No compliance eligibility hook before shift assignment | Must add `ComplianceMatrixService::canAssignToShift()` call |
| **Timesheets** | No allowance fields (mileage, sleepover, on-call) | Must add columns to timesheets table |
| **Timesheets** | No payroll locking mechanism | Must add payroll run lock check |
| **Incidents** | No HR confidentiality flag | Must add `is_hr_confidential` + `hr_case_id` columns |
| **StaffProfile** | Too minimal for HR needs | HR module creates its own `hr_employee_profiles` extending it |
| **StaffInduction** | Model class not created (only migration) | Must create model class |
| **StaffCompetencyAssessment** | Model class not created (only migration) | Must create model class |

### Minimum Decisions to Start
1. **Payroll system** — which one? (determines export format)
2. **Email provisioning** — manual or automated? (determines onboarding flow)
3. **Police vetting cadence** — every X years? (determines compliance matrix config)
4. **Leave balance source** — payroll sync or HR-managed? (determines leave module scope)
5. **Fatigue thresholds** — specific hours/rest rules? (determines rostering integration)

---

## 26) AUTO-GENERATED FOLLOW-UP ONE-SHOT PROMPTS

### Prompt 1: Identity & Work Email Provisioning
```
Oblivion Findings – Identity & Work Email Provisioning One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11 + Inertia.js + React/TypeScript).
Design and implement a work email provisioning system that integrates with the HR module's onboarding workflow.

Context:
- HR module creates hr_employee_profiles with work_email field
- hr_offers table has work_email_provisioned boolean flag
- Onboarding checklist has "Create work email" as a blocker task

Requirements:
1. Define an EmailProvisioningInterface with implementations for:
   a) ManualProvisioning (HR enters email after creating externally)
   b) Microsoft365Provisioning (Graph API integration)
   c) GoogleWorkspaceProvisioning (Admin SDK integration)
2. Config-driven provider selection (config/hr.php)
3. Provisioning triggered when offer is accepted
4. Fallback to manual if API fails
5. Email validation (confirm mailbox exists before onboarding proceeds)
6. Deprovisioning on offboarding (disable, don't delete)

Deliverables: Interface, 3 implementations, config, migration for provisioning log table, tests.
```

### Prompt 2: Payroll Export Mapping
```
Oblivion Findings – Payroll Export Mapping One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11).
Implement a configurable payroll export system for the HR module.

Context:
- hr_payroll_runs and hr_payroll_run_items tables exist
- Timesheets have approval workflow (draft→submitted→approved)
- Timesheet allowance fields: mileage_km, sleepover, on_call, public_holiday

Requirements:
1. PayrollExportInterface with implementations for:
   a) GenericCsvExport (configurable column mapping)
   b) PayHeroExport (PayHero API format)
   c) iPayrollExport (iPayroll CSV format)
   d) XeroExport (Xero Payroll API)
2. Payroll run locking: once locked, timesheets in period cannot be edited
3. Pay period configuration (weekly/fortnightly/monthly, start day)
4. Allowance mapping: configurable allowance codes per payroll system
5. Public holiday detection (NZ public holidays + regional)
6. Export versioning: re-export creates new version, tracks changes
7. Reconciliation report: compare export vs approved timesheets

Deliverables: Interface, 4 implementations, config, controller, frontend page, tests.
```

### Prompt 3: Rostering Eligibility & Fatigue Rules
```
Oblivion Findings – Rostering Eligibility & Fatigue Rules One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11).
Implement real-time compliance and fatigue checking for the rostering module.

Context:
- Existing ShiftController has assign() and store() methods
- Existing RosteringController shows weekly dashboard with capacity warnings
- HR module has ComplianceMatrixService and hr_staff_compliance_status table
- HR module has hr_fatigue_rules in config/hr.php

Requirements:
1. HrRosteringContract interface implemented by ComplianceMatrixService
2. Modify ShiftController::assign() to call checkEligibility() before saving
3. Modify ShiftController::store() to validate compliance on creation
4. Add UI badges to rostering dashboard:
   a) Green: fully compliant
   b) Amber: soft warnings (expiring within 30 days)
   c) Red: hard-stop blocks (expired mandatory requirement)
5. Fatigue rule evaluation:
   a) Calculate hours worked in 7-day and 24-hour windows
   b) Calculate rest gap between consecutive shifts
   c) Return warnings/blocks per config thresholds
6. Approved leave overlay on weekly roster view
7. "Cannot assign" tooltip with specific reasons

Deliverables: Contract interface, service implementation, ShiftController modifications,
rostering UI updates, fatigue calculator, tests with edge cases.
```

### Prompt 4: Police Vetting Consent + Secure Storage
```
Oblivion Findings – Police Vetting Consent + Secure Storage One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11).
Implement NZ Police Vetting consent capture and secure result storage.

Context:
- StaffBackgroundCheck model exists with full status workflow
- HR module adds consent_captured_at, consent_method, nz_police_vetting_ref fields
- Organisation is an approved agency under NZ Police Vetting Service
- Results may contain sensitive disclosures (restricted to HR Admin only)

Requirements:
1. Consent capture workflow:
   a) Generate consent form from HR template (merge fields: employee name, date, purpose)
   b) Employee acknowledges digitally (timestamp, IP, user agent captured)
   c) Consent document stored in private disk with access logging
2. Result storage:
   a) Disclosure details encrypted at rest (beyond standard DB encryption)
   b) Field-level access control: only hr.vetting.view_disclosures can see disclosure_details
   c) Every view of vetting detail page logged to audit_logs
3. Renewal workflow:
   a) Configurable vetting cadence (default: 3 years)
   b) Renewal reminders at 90, 60, 30 days before expiry
   c) New consent required for each renewal
4. Evidence pack generation:
   a) Include vetting status summary (cleared/not cleared)
   b) EXCLUDE disclosure details from evidence packs
   c) Include consent document reference

Deliverables: Consent form template, digital acknowledgement flow, encrypted storage,
access logging middleware, renewal job, evidence pack integration, tests.
```

### Prompt 5: HR Case Management Confidentiality
```
Oblivion Findings – HR Case Management Confidentiality One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11).
Implement the HR case management system with strict confidentiality controls.

Context:
- hr_cases and hr_case_events tables designed (see HR module design doc)
- hr_disciplinary_actions table with good faith checklist
- Existing ClientIncident model extended with is_hr_confidential and hr_case_id
- RBAC permissions: hr.cases.view, hr.cases.manage, hr.disciplinary.view, hr.disciplinary.manage

Requirements:
1. Case CRUD with access_list enforcement:
   a) Only users in access_list OR with hr.cases.view can see case
   b) access_list editable only by case creator or HR Admin
   c) Audit log every access_list change
2. Confidential incident linking:
   a) When incident marked is_hr_confidential, remove from standard incident lists
   b) Link to HR case via hr_case_id
   c) Only HR-permitted users can see confidential incidents
3. Disciplinary workflow:
   a) Stage transitions with validation (can't skip stages)
   b) Good faith checklist must be completed before outcome
   c) Template generation for warning letters (merge fields)
   d) Response period tracking with deadline enforcement
4. Case timeline view:
   a) Chronological events with visibility filtering
   b) Document attachments with restricted access
   c) Communication log
5. Strict audit: every view, edit, download logged

Deliverables: HrCaseController, DisciplinaryController, case timeline component,
confidentiality middleware, template merge service, tests.
```

### Prompt 6: Leave Management & Payroll Integration
```
Oblivion Findings – Leave Management & Balance Tracking One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11).
Implement leave management with approval workflows and balance tracking.

Context:
- hr_leave_requests and hr_leave_balances tables designed
- Existing StaffTimeOff model creates availability blocks
- Existing rostering shows time-off conflicts
- Timesheets link to shifts

Requirements:
1. Leave request submission and approval workflow
2. Leave balance tracking (accrual or payroll sync)
3. Approved leave auto-creates StaffTimeOff record
4. Rostering conflict detection and resolution UI
5. Supporting document upload (medical certs) with privacy protection
6. NZ leave entitlements: annual (4 weeks), sick (10 days), bereavement (3/1 days)
7. Public holiday leave type with Mondayisation rules

Deliverables: LeaveController, MyHrController leave section, approval notifications,
balance calculation service, StaffTimeOff integration, tests.
```

### Prompt 7: Compliance Matrix Configuration UI
```
Oblivion Findings – Compliance Matrix Configuration UI One-Shot Prompt

You are the lead engineer for the Oblivion Findings platform (Laravel 11 + React/TypeScript).
Build the compliance matrix configuration and dashboard UI.

Context:
- hr_compliance_requirements, hr_compliance_matrix, hr_staff_compliance_status tables exist
- ComplianceMatrixService evaluates status nightly and in real-time
- Roles defined in RBAC: support_worker, team_lead, coordinator, etc.

Requirements:
1. Matrix configuration page (HR Admin only):
   a) CRUD for compliance requirements
   b) Drag-drop matrix assignment (requirement × role grid)
   c) Hard-stop vs soft-warning toggle per requirement
2. Compliance dashboard:
   a) Overview cards: compliant %, expiring soon %, expired %
   b) Filterable table: by site, role, status, requirement
   c) Individual employee compliance detail view
3. "Overdue" widgets on main dashboard
4. Export compliance report (CSV/PDF)

Deliverables: ComplianceMatrixController, React pages (matrix config, dashboard, detail),
report export service, tests.
```

---

## FINAL QUALITY CHECK

| Criterion | Status |
|---|---|
| HR module is simple yet complete | Yes — 6 top-level nav items, 1-3 click workflows |
| Integrates with rostering/timesheets/assets/incidents/control room | Yes — defined contracts and integration points |
| Compliance matrix drives expiry alerts and rostering eligibility | Yes — hard-stop/soft-warning with nightly + real-time evaluation |
| Sensitive HR data is protected and audited | Yes — field-level access control, enhanced audit logging, encrypted fields |
| Work-email-only rule after hire is enforced | Yes — onboarding blocks until work_email_provisioned; all HR comms to work_email |
| Evidence packs and reports support audits | Yes — HrEvidencePackService with redaction and permissions |
| NZ compliance supported (Privacy Act, Ngā Paerewa, Police Vetting, Good Faith) | Yes — consent capture, vetting workflow, good faith checklist, training evidence |
