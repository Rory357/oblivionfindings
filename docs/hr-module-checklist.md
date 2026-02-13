# Oblivion Findings — HR Module Implementation Checklist

**Total Tasks: 247**
**Phases: 4 (MVP → Full)**

Legend: `[ ]` = Not started | `[~]` = In progress | `[x]` = Complete

---

## PHASE 1: MVP — Core HR Foundation

### 1.1 Database Migrations (13 migrations)

- [ ] Migration 1: `create_hr_recruitment_tables`
  - [ ] `hr_candidates` table (id, tenant_id, first_name, last_name, preferred_name, personal_email, personal_phone, source, source_detail, status, current_stage_entered_at, privacy_consent_given_at, privacy_consent_ip, notes, tags, created_by, updated_by, timestamps, soft_deletes)
  - [ ] `hr_applications` table (id, tenant_id, candidate_id FK, position_title, position_role, target_site_id FK, cv_storage_path, cv_original_name, cover_letter, answers, status, rejection_reason, timestamps)
  - [ ] `hr_interviews` table (id, application_id FK, scheduled_at, duration_minutes, location, interview_type, interviewers, status, notes, rating, outcome, completed_by FK, timestamps)
  - [ ] `hr_reference_checks` table (id, application_id FK, referee_name, referee_email, referee_phone, referee_relationship, status, requested_at, received_at, verified_at, reference_notes, verified_by FK, timestamps)
  - [ ] `hr_offers` table (id, application_id FK, template_id FK, position_title, position_role, proposed_start_date, employment_type, hours_per_week, hourly_rate, annual_salary, primary_site_id FK, conditions, approval_status, approved_by FK, approved_at, sent_at, response, response_at, response_notes, work_email_provisioned, work_email, created_by, updated_by, timestamps)
  - [ ] All indexes per design doc
  - [ ] All foreign key constraints with appropriate cascade rules

- [ ] Migration 2: `create_hr_employee_profiles_tables`
  - [ ] `hr_employee_profiles` table (all fields per design doc section 4)
  - [ ] `hr_employee_profile_versions` table (id, employee_profile_id FK, field_name, old_value, new_value, changed_by FK, reason, effective_from, created_at)
  - [ ] Encrypted fields: date_of_birth, home_address, bank_account, ird_number, hourly_rate, annual_salary
  - [ ] Indexes: (tenant_id, is_active), (user_id), (employee_number), (primary_site_id)

- [ ] Migration 3: `create_hr_compliance_matrix_tables`
  - [ ] `hr_compliance_requirements` table (id, tenant_id, code UNIQUE, name, description, category, check_type, reference_id, validity_months, renewal_reminder_days, hard_stop, is_active, created_by, updated_by, timestamps)
  - [ ] `hr_compliance_matrix` table (id, tenant_id, requirement_id FK, role, site_type, is_mandatory, notes, timestamps, UNIQUE constraint)
  - [ ] `hr_staff_compliance_status` table (id, tenant_id, user_id FK, requirement_id FK, status, evidence_type, evidence_id, valid_from, expires_at, exemption_reason, exempted_by FK, last_checked_at, next_check_at, timestamps)
  - [ ] All indexes per design doc

- [ ] Migration 4: `create_hr_leave_tables`
  - [ ] `hr_leave_requests` table (all fields per design doc section 11)
  - [ ] `hr_leave_balances` table (all fields per design doc section 11)
  - [ ] Unique constraint on leave_balances (tenant_id, user_id, leave_type, year)

- [ ] Migration 5: `create_hr_onboarding_tables`
  - [ ] `hr_onboarding_templates` table (id, tenant_id, role, site_type, tasks JSON, is_active, created_by, updated_by, timestamps)
  - [ ] `hr_onboarding_checklists` table (id, tenant_id, employee_profile_id FK, template_key, status, started_at, completed_at, due_date, created_by, timestamps)
  - [ ] `hr_onboarding_tasks` table (all fields per design doc section 5)
  - [ ] `hr_offboarding_checklists` table (same structure as onboarding)

- [ ] Migration 6: `create_hr_performance_tables`
  - [ ] `hr_supervision_notes` table (all fields per design doc section 14)
  - [ ] `hr_performance_reviews` table (all fields per design doc section 14)
  - [ ] `hr_probation_reviews` table (all fields per design doc section 14)

- [ ] Migration 7: `create_hr_cases_tables`
  - [ ] `hr_cases` table (all fields per design doc section 13)
  - [ ] `hr_case_events` table (all fields per design doc section 13)
  - [ ] `hr_disciplinary_actions` table (all fields per design doc section 15)

- [ ] Migration 8: `create_hr_policy_tables`
  - [ ] `hr_policies` table (all fields per design doc section 17)
  - [ ] `hr_policy_versions` table (all fields per design doc section 17)
  - [ ] `hr_policy_attestations` table (all fields per design doc section 17)

- [ ] Migration 9: `create_hr_documents_tables`
  - [ ] `hr_document_templates` table (all fields per design doc section 19)
  - [ ] `hr_documents` table (all fields per design doc section 19)

- [ ] Migration 10: `create_hr_payroll_tables`
  - [ ] `hr_payroll_runs` table (all fields per design doc section 12)
  - [ ] `hr_payroll_run_items` table (all fields per design doc section 12)

- [ ] Migration 11: `create_hr_driver_eligibility_table`
  - [ ] `hr_driver_eligibility` table (all fields per design doc section 9)

- [ ] Migration 12: `create_hr_wellbeing_indicators_table`
  - [ ] `hr_wellbeing_indicators` table (all fields per design doc section 16)

- [ ] Migration 13: `add_hr_fields_to_existing_tables`
  - [ ] ALTER `timesheets`: add mileage_km, sleepover, on_call, allowance_notes, public_holiday
  - [ ] ALTER `client_incidents`: add is_hr_confidential (boolean, default: false), hr_case_id (FK nullable)
  - [ ] ALTER `staff_background_checks`: add nz_police_vetting_ref, consent_captured_at, consent_method, consent_document_path, approved_agency_ref

### 1.2 Models (30 models)

- [ ] `app/Domain/Hr/Models/HrCandidate.php` (fillable, casts, relationships, scopes, AuditableChanges, SoftDeletes)
- [ ] `app/Domain/Hr/Models/HrApplication.php`
- [ ] `app/Domain/Hr/Models/HrInterview.php`
- [ ] `app/Domain/Hr/Models/HrReferenceCheck.php`
- [ ] `app/Domain/Hr/Models/HrOffer.php`
- [ ] `app/Domain/Hr/Models/HrEmployeeProfile.php` (encrypted casts for sensitive fields, version tracking)
- [ ] `app/Domain/Hr/Models/HrEmployeeProfileVersion.php`
- [ ] `app/Domain/Hr/Models/HrComplianceRequirement.php`
- [ ] `app/Domain/Hr/Models/HrComplianceMatrix.php`
- [ ] `app/Domain/Hr/Models/HrStaffComplianceStatus.php`
- [ ] `app/Domain/Hr/Models/HrDriverEligibility.php`
- [ ] `app/Domain/Hr/Models/HrLeaveRequest.php`
- [ ] `app/Domain/Hr/Models/HrLeaveBalance.php`
- [ ] `app/Domain/Hr/Models/HrOnboardingTemplate.php`
- [ ] `app/Domain/Hr/Models/HrOnboardingChecklist.php`
- [ ] `app/Domain/Hr/Models/HrOnboardingTask.php`
- [ ] `app/Domain/Hr/Models/HrOffboardingChecklist.php`
- [ ] `app/Domain/Hr/Models/HrSupervisionNote.php`
- [ ] `app/Domain/Hr/Models/HrPerformanceReview.php`
- [ ] `app/Domain/Hr/Models/HrProbationReview.php`
- [ ] `app/Domain/Hr/Models/HrCase.php`
- [ ] `app/Domain/Hr/Models/HrCaseEvent.php`
- [ ] `app/Domain/Hr/Models/HrDisciplinaryAction.php`
- [ ] `app/Domain/Hr/Models/HrPolicy.php`
- [ ] `app/Domain/Hr/Models/HrPolicyVersion.php`
- [ ] `app/Domain/Hr/Models/HrPolicyAttestation.php`
- [ ] `app/Domain/Hr/Models/HrDocumentTemplate.php`
- [ ] `app/Domain/Hr/Models/HrDocument.php`
- [ ] `app/Domain/Hr/Models/HrPayrollRun.php`
- [ ] `app/Domain/Hr/Models/HrPayrollRunItem.php`
- [ ] `app/Domain/Hr/Models/HrWellbeingIndicator.php`
- [ ] Create missing model: `app/Models/StaffCompetencyAssessment.php` (migration already exists)
- [ ] Create missing model: `app/Models/StaffInduction.php` (migration already exists)

### 1.3 Services (9 services)

- [ ] `app/Domain/Hr/Services/ComplianceMatrixService.php`
  - [ ] `evaluateAllStaff()` — nightly batch evaluation
  - [ ] `evaluateStaff(User $user)` — single staff evaluation
  - [ ] `canAssignToShift(User $user, Shift $shift)` — real-time check
  - [ ] `getHardStopFailures(User $user)` — blocking failures
  - [ ] `getSoftWarnings(User $user)` — non-blocking warnings
  - [ ] `getComplianceSummary(int $siteId)` — dashboard data
- [ ] `app/Domain/Hr/Services/RecruitmentService.php`
  - [ ] `createCandidate()` with privacy consent
  - [ ] `advanceStage()` state machine
  - [ ] `convertToEmployee()` with validation
- [ ] `app/Domain/Hr/Services/OnboardingService.php`
  - [ ] `generateChecklist()` from template by role+site
  - [ ] `completeTask()` with sign-off validation
  - [ ] `generateOffboardingChecklist()` with asset list
- [ ] `app/Domain/Hr/Services/LeaveService.php`
  - [ ] `submitRequest()` with balance check
  - [ ] `approveRequest()` → creates StaffTimeOff
  - [ ] `declineRequest()` with notification
  - [ ] `calculateBalance()` for leave type+year
- [ ] `app/Domain/Hr/Services/PayrollExportService.php`
  - [ ] `createRun()` for pay period
  - [ ] `lockRun()` — prevents timesheet edits
  - [ ] `generateExport()` — CSV output
  - [ ] `getRunItems()` — aggregate timesheets
- [ ] `app/Domain/Hr/Services/HrEvidencePackService.php`
  - [ ] `generate()` — compliance audit pack
  - [ ] Redaction of sensitive fields
  - [ ] Permission-aware content inclusion
- [ ] `app/Domain/Hr/Services/HrDocumentMergeService.php`
  - [ ] `mergeTemplate()` — replace merge fields with employee data
  - [ ] `generatePdf()` — from template (or HTML)
- [ ] `app/Domain/Hr/Services/WellbeingIndicatorService.php`
  - [ ] `calculateIndicators()` — overtime, fatigue, sick leave trends
  - [ ] `getFlaggedStaff()` — monitor/concern list
- [ ] `app/Domain/Hr/Services/HrRosteringContract.php` (interface)
  - [ ] Define contract methods per design doc section 10

### 1.4 Jobs (5 jobs)

- [ ] `app/Domain/Hr/Jobs/EvaluateComplianceMatrixJob.php`
  - [ ] Schedule: daily 01:00
  - [ ] Evaluate all active employees against compliance matrix
  - [ ] Upsert hr_staff_compliance_status records
  - [ ] Trigger notifications for status changes
- [ ] `app/Domain/Hr/Jobs/SendExpiryRemindersJob.php`
  - [ ] Schedule: daily 08:00
  - [ ] Check expiring credentials, training, vetting, licences
  - [ ] Send notifications at configured reminder intervals
- [ ] `app/Domain/Hr/Jobs/CalculateWellbeingIndicatorsJob.php`
  - [ ] Schedule: daily 02:00
  - [ ] Calculate overtime, consecutive days, sick leave trends
  - [ ] Upsert hr_wellbeing_indicators
- [ ] `app/Domain/Hr/Jobs/ProcessLeaveBalanceAccrualJob.php`
  - [ ] Schedule: monthly 1st
  - [ ] Accrue leave balances per employment type
- [ ] `app/Domain/Hr/Jobs/ArchiveCandidateDataJob.php`
  - [ ] Schedule: weekly
  - [ ] Purge expired candidate data per retention policy

### 1.5 Notifications (7 notification classes)

- [ ] `app/Domain/Hr/Notifications/ComplianceExpiryNotification.php` (database + email)
- [ ] `app/Domain/Hr/Notifications/LeaveRequestNotification.php` (database + email)
- [ ] `app/Domain/Hr/Notifications/LeaveApprovedNotification.php` (database + email)
- [ ] `app/Domain/Hr/Notifications/OnboardingTaskAssignedNotification.php` (database + email)
- [ ] `app/Domain/Hr/Notifications/PolicyAttestationDueNotification.php` (database + email)
- [ ] `app/Domain/Hr/Notifications/PerformanceReviewDueNotification.php` (database)
- [ ] `app/Domain/Hr/Notifications/HrCaseUpdateNotification.php` (database)

### 1.6 RBAC Permissions (update RbacSeeder)

- [ ] Add all HR permissions to `database/seeders/RbacSeeder.php`:
  - [ ] hr.recruitment.view, hr.recruitment.manage
  - [ ] hr.employees.viewAny, hr.employees.viewOwn, hr.employees.manage
  - [ ] hr.employees.viewFinancial, hr.employees.viewRestricted
  - [ ] hr.compliance.view, hr.compliance.manage
  - [ ] hr.training.view, hr.training.manage
  - [ ] hr.vetting.view, hr.vetting.manage, hr.vetting.view_disclosures
  - [ ] hr.leave.viewAny, hr.leave.viewOwn, hr.leave.approve, hr.leave.manage
  - [ ] hr.performance.view, hr.performance.manage
  - [ ] hr.cases.view, hr.cases.manage
  - [ ] hr.disciplinary.view, hr.disciplinary.manage
  - [ ] hr.policies.view, hr.policies.manage, hr.policies.attest
  - [ ] hr.documents.view, hr.documents.manage
  - [ ] hr.payroll.view, hr.payroll.export
  - [ ] hr.reports.view, hr.reports.export
  - [ ] hr.driver.view, hr.driver.manage
  - [ ] hr.wellbeing.view
  - [ ] hr.onboarding.view, hr.onboarding.manage
- [ ] Assign permissions to roles: admin (all), hr (most), provider_manager (most), team_lead (limited), support_worker (self-service only)
- [ ] Add HR permissions to `HandleInertiaRequests.php` `getUserPermissions()` method

### 1.7 Routes

- [ ] Create `routes/hr.php`
  - [ ] HR group with auth + permission middleware
  - [ ] Recruitment routes (CRUD candidates, applications, interviews, offers)
  - [ ] Employee profile routes (index, show, edit, update)
  - [ ] Compliance routes (dashboard, matrix config, requirements CRUD)
  - [ ] Training dashboard routes
  - [ ] Vetting register routes
  - [ ] Driver eligibility routes
  - [ ] Leave routes (index, store, approve, decline, cancel)
  - [ ] Onboarding routes (checklists, tasks)
  - [ ] Performance routes (supervision notes, reviews, probation)
  - [ ] HR cases routes (CRUD, events, disciplinary)
  - [ ] Policy routes (library, versions, attestations)
  - [ ] Document routes (templates, CRUD)
  - [ ] Payroll routes (runs, lock, export)
  - [ ] Report routes (index, generate, export)
  - [ ] My HR routes (self-service)
- [ ] Add `require __DIR__ . '/hr.php';` to `routes/web.php`

### 1.8 Controllers (20 controllers)

- [ ] `app/Http/Controllers/Hr/RecruitmentController.php` (index, pipeline view)
- [ ] `app/Http/Controllers/Hr/CandidateController.php` (CRUD candidates, advance stage)
- [ ] `app/Http/Controllers/Hr/EmployeeProfileController.php` (index, show, edit, update)
- [ ] `app/Http/Controllers/Hr/ComplianceController.php` (dashboard, employee detail)
- [ ] `app/Http/Controllers/Hr/ComplianceMatrixController.php` (requirements CRUD, matrix config)
- [ ] `app/Http/Controllers/Hr/TrainingDashboardController.php` (overdue, due soon, by site)
- [ ] `app/Http/Controllers/Hr/VettingController.php` (register, consent capture, status update)
- [ ] `app/Http/Controllers/Hr/DriverEligibilityController.php` (register, approve, suspend)
- [ ] `app/Http/Controllers/Hr/LeaveController.php` (index, store, approve, decline, balances)
- [ ] `app/Http/Controllers/Hr/OnboardingController.php` (checklists, complete task, templates)
- [ ] `app/Http/Controllers/Hr/SupervisionController.php` (notes CRUD)
- [ ] `app/Http/Controllers/Hr/PerformanceReviewController.php` (reviews CRUD, probation)
- [ ] `app/Http/Controllers/Hr/HrCaseController.php` (cases CRUD, events, timeline)
- [ ] `app/Http/Controllers/Hr/DisciplinaryController.php` (actions CRUD, good faith checklist)
- [ ] `app/Http/Controllers/Hr/PolicyController.php` (library CRUD, versions)
- [ ] `app/Http/Controllers/Hr/PolicyAttestationController.php` (attest, status)
- [ ] `app/Http/Controllers/Hr/HrDocumentController.php` (templates, documents CRUD)
- [ ] `app/Http/Controllers/Hr/PayrollExportController.php` (runs, lock, export)
- [ ] `app/Http/Controllers/Hr/HrReportController.php` (index, generate, export)
- [ ] `app/Http/Controllers/Hr/MyHrController.php` (self-service: profile, leave, training, policies)

### 1.9 Frontend Pages (React/TypeScript)

#### Navigation & Layout
- [ ] Add HR group to `app-sidebar.tsx` with 6 nav items (gated by hr.* permissions)
- [ ] Add "My HR" link to user menu dropdown
- [ ] Create HR layout component (if needed) or use AppLayout

#### Recruitment Pages
- [ ] `resources/js/pages/hr/recruitment/index.tsx` — Pipeline/kanban view
- [ ] `resources/js/pages/hr/recruitment/candidates/show.tsx` — Candidate detail
- [ ] `resources/js/pages/hr/recruitment/candidates/create.tsx` — New candidate form
- [ ] `resources/js/pages/hr/recruitment/offers/create.tsx` — Offer form

#### People Pages
- [ ] `resources/js/pages/hr/people/index.tsx` — Employee list with filters
- [ ] `resources/js/pages/hr/people/show.tsx` — Employee profile (tabbed: overview, compliance, training, vetting, leave, performance, documents, assets)
- [ ] `resources/js/pages/hr/people/edit.tsx` — Edit employee profile

#### Compliance Pages
- [ ] `resources/js/pages/hr/compliance/index.tsx` — Compliance dashboard (cards + table)
- [ ] `resources/js/pages/hr/compliance/matrix.tsx` — Matrix configuration (requirement × role grid)
- [ ] `resources/js/pages/hr/compliance/training.tsx` — Training dashboard
- [ ] `resources/js/pages/hr/compliance/vetting.tsx` — Vetting register
- [ ] `resources/js/pages/hr/compliance/drivers.tsx` — Driver eligibility register

#### Leave Pages
- [ ] `resources/js/pages/hr/leave/index.tsx` — Leave requests list + approval queue
- [ ] `resources/js/pages/hr/leave/balances.tsx` — Leave balances overview

#### Performance Pages
- [ ] `resources/js/pages/hr/performance/index.tsx` — Supervision notes + reviews
- [ ] `resources/js/pages/hr/performance/cases.tsx` — HR cases list
- [ ] `resources/js/pages/hr/performance/cases/show.tsx` — Case detail + timeline

#### Policy Pages
- [ ] `resources/js/pages/hr/policies/index.tsx` — Policy library
- [ ] `resources/js/pages/hr/policies/attest.tsx` — Policy attestation page

#### Report Pages
- [ ] `resources/js/pages/hr/reports/index.tsx` — Reports dashboard

#### My HR (Self-Service) Pages
- [ ] `resources/js/pages/hr/my/index.tsx` — My HR dashboard
- [ ] `resources/js/pages/hr/my/leave.tsx` — My leave requests + balances
- [ ] `resources/js/pages/hr/my/training.tsx` — My training status
- [ ] `resources/js/pages/hr/my/policies.tsx` — My policy attestations

### 1.10 Config & Feature Flags

- [ ] Add HR section to `config/features.php`:
  ```php
  'hr' => [
      'enabled' => true,
      'recruitment' => true,
      'compliance_matrix' => true,
      'leave_management' => true,
      'payroll_export' => false,
      'wellbeing_dashboard' => false,
      'driver_eligibility' => false,
  ],
  ```
- [ ] Create `config/hr.php` with fatigue rules, leave types, retention periods, vetting cadence

### 1.11 Existing Module Modifications

- [ ] **ShiftController.php**: Add ComplianceMatrixService::canAssignToShift() call in assign() method
- [ ] **RosteringController.php**: Add compliance status badges + leave overlay to dashboard data
- [ ] **TimesheetController.php**: Add payroll lock check in update/submit methods
- [ ] **ClientIncidentController.php**: Add is_hr_confidential filtering to index queries
- [ ] **HandleInertiaRequests.php**: Add `hr` permissions block to getUserPermissions()
- [ ] **app-sidebar.tsx**: Add HR navigation group

---

## PHASE 2: Integration & Workflows

### 2.1 Rostering Integration
- [ ] Implement HrRosteringContract interface
- [ ] Add compliance badge component to shift cards
- [ ] Add leave overlay to rostering weekly view
- [ ] Add fatigue calculator to capacity planning
- [ ] Add "Cannot assign" tooltip with specific compliance reasons
- [ ] Test: shift assignment blocked when hard-stop compliance failure
- [ ] Test: shift assignment shows warning for soft failures
- [ ] Test: leave conflict badge on shifts overlapping approved leave

### 2.2 Timesheet Integration
- [ ] Add allowance fields to timesheet create/edit forms
- [ ] Add payroll run locking mechanism to timesheet update flow
- [ ] Add payroll export page and CSV generation
- [ ] Test: locked timesheets cannot be edited
- [ ] Test: export includes all allowances

### 2.3 Asset Integration
- [ ] Employee profile "Issued Assets" tab reads from asset_assignments
- [ ] Onboarding auto-populates asset issue tasks from role template
- [ ] Offboarding auto-populates asset return tasks from active assignments
- [ ] Test: offboarding checklist lists all unreturned assets

### 2.4 Incident Integration
- [ ] Add `is_hr_confidential` toggle to incident create/edit form
- [ ] Filter confidential incidents from standard incident lists
- [ ] Link incidents to HR cases via hr_case_id
- [ ] Test: non-HR users cannot see confidential incidents

---

## PHASE 3: Advanced Features

### 3.1 Disciplinary Workflow
- [ ] Stage transition validation (no skipping stages)
- [ ] Good faith checklist UI component
- [ ] Template generation for warning letters
- [ ] Response period tracking with deadline notifications
- [ ] Appeal workflow
- [ ] Test: cannot set outcome without good faith checklist completion

### 3.2 Policy Attestations
- [ ] Policy library CRUD with versioning
- [ ] Attestation capture (timestamp, IP, user_agent)
- [ ] Compliance matrix integration for attestation requirements
- [ ] Bulk reminder sending for outstanding attestations
- [ ] Test: policy version update triggers re-attestation requirement

### 3.3 Document Templates
- [ ] Template CRUD with merge field definitions
- [ ] Merge service: replace {{fields}} with employee data
- [ ] Generated document storage with restricted access
- [ ] Template categories: contract, variation, letter, warning
- [ ] Test: merge produces correct output for all field types

### 3.4 Wellbeing Dashboard
- [ ] Nightly indicator calculation
- [ ] Dashboard page with aggregate stats (no individual medical data)
- [ ] EAP resource hub static page
- [ ] Test: flag levels calculated correctly from overtime/sick data

### 3.5 Evidence Packs
- [ ] HrEvidencePackService implementation
- [ ] Compliance audit pack generation
- [ ] Sensitive data redaction in exports
- [ ] Permission-aware content inclusion
- [ ] Test: vetting disclosures excluded from standard pack

---

## PHASE 4: Polish & Compliance

### 4.1 Driver Eligibility
- [ ] Licence detail capture and expiry tracking
- [ ] "Can drive clients" approval workflow
- [ ] Rostering integration: block driving-required shifts for ineligible staff
- [ ] Incident link: driving incidents reset eligibility flag
- [ ] Test: driving-required shift blocked for suspended driver

### 4.2 Advanced Reporting
- [ ] Overdue training report with export
- [ ] Vetting due report (30/60/90 days)
- [ ] Policy attestation outstanding report
- [ ] Headcount/turnover report
- [ ] Leave balance report
- [ ] Compliance matrix status report
- [ ] Timesheet approval backlog report
- [ ] Onboarding progress report

### 4.3 Privacy & Data Retention
- [ ] Candidate data retention job (archive after configurable period)
- [ ] Employee data retention rules (post-termination)
- [ ] Sensitive field access logging (every view logged)
- [ ] Data export for Privacy Act requests
- [ ] Test: expired candidate data purged on schedule

### 4.4 NZ Police Vetting Enhancements
- [ ] Consent form template with merge fields
- [ ] Digital consent acknowledgement capture (timestamp, IP, method)
- [ ] Encrypted disclosure storage
- [ ] Access logging for every vetting detail view
- [ ] Renewal reminder automation
- [ ] Test: consent required before vetting can be submitted

### 4.5 Seed Data
- [ ] Create `database/seeders/HrSeeder.php`:
  - [ ] 20 employee profiles across 3 sites
  - [ ] 5 candidates at different recruitment stages
  - [ ] Compliance requirements for 3 roles (support_worker, team_lead, coordinator)
  - [ ] Training courses linked to compliance requirements
  - [ ] Mixed compliance statuses (compliant, expiring, expired, not_started)
  - [ ] Sample leave balances and requests
  - [ ] Sample onboarding checklist (one in progress, one completed)
  - [ ] 3 sample policies with attestation records

---

## TESTING CHECKLIST

### Unit Tests
- [ ] ComplianceMatrixService: evaluateStaff with various compliance states
- [ ] ComplianceMatrixService: canAssignToShift with hard-stop and soft warnings
- [ ] LeaveService: balance calculations, overlap detection
- [ ] PayrollExportService: CSV generation with allowances
- [ ] OnboardingService: checklist generation from template
- [ ] RecruitmentService: state machine transitions
- [ ] WellbeingIndicatorService: flag level calculations
- [ ] HrDocumentMergeService: merge field replacement

### Feature Tests
- [ ] Recruitment pipeline: create candidate → interview → offer → accept → employee
- [ ] Leave workflow: submit → approve → StaffTimeOff created → roster conflict shown
- [ ] Onboarding: checklist generated → tasks completed → checklist completed
- [ ] Compliance check: expired training blocks shift assignment (hard stop)
- [ ] Compliance check: expiring training shows warning (soft warning)
- [ ] Payroll: lock run → timesheets immutable → export generated
- [ ] HR case: create → add events → disciplinary → outcome → close
- [ ] Policy: publish new version → existing attestations stale → reminders sent
- [ ] Self-service: employee views own profile, submits leave, attests policy

### Permission Tests
- [ ] HR Admin can access all HR areas
- [ ] Team Leader can only see direct reports
- [ ] Employee can only access My HR self-service
- [ ] Payroll can view financials but not restricted notes
- [ ] Auditor gets read-only access to reports
- [ ] Non-HR users cannot see confidential incidents

### Edge Case Tests
- [ ] Candidate converted without approved offer → blocked
- [ ] Vetting expired with future shifts → notification sent, shifts flagged
- [ ] Leave approved but roster conflict → conflict badge shown
- [ ] Timesheet edited after payroll lock → rejected with 422
- [ ] Role changed → compliance matrix re-evaluated automatically
- [ ] Duplicate candidate email → existing record shown

---

## DOCUMENTATION

- [ ] Update MEMORY.md with HR module patterns and key file locations
- [ ] API documentation for HrRosteringContract interface
- [ ] Compliance matrix configuration guide (for HR admins)
- [ ] Leave management user guide
- [ ] Onboarding template configuration guide

---

## DEPLOYMENT CHECKLIST

- [ ] Run all 13 migrations
- [ ] Run RbacSeeder for new HR permissions
- [ ] Run HrSeeder for demo data (dev/staging only)
- [ ] Register HR jobs in scheduler (console/commands or Kernel)
- [ ] Verify config/features.php HR flags
- [ ] Verify config/hr.php defaults
- [ ] Smoke test: login as admin → navigate to /hr/people
- [ ] Smoke test: login as support_worker → navigate to /hr/my
- [ ] Smoke test: create candidate → advance through pipeline
- [ ] Smoke test: submit leave request → approve → check roster

---

**Total Implementation Items: ~247**
**Estimated New Files: ~80 (30 models + 9 services + 5 jobs + 7 notifications + 20 controllers + ~25 frontend pages)**
**Estimated New Database Tables: ~30**
**Estimated Existing File Modifications: ~10**
