# HR Deferred Audit Backlog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement and verify all 27 requirements in the approved HR deferred-audit design without reopening the settled D-7, D-10, or D-11 ownership decisions.

**Architecture:** Work proceeds as thirteen independently committable TDD slices on `codex/hr-deferred-backlog`. Domain invariants live in services, organisation checks happen before writes, UI changes reuse existing HR primitives, and an append-only progress ledger records deliberate red and final green evidence.

**Tech Stack:** Laravel 13/PHP 8.4, Pest 4, Inertia React 19, TypeScript, Vitest 4, Tailwind/design-system HR components, MySQL/SQLite-compatible migrations, Vite/Wayfinder.

---

## File map

- `HR_DEFERRED_BACKLOG_PROGRESS.md` — execution ledger keyed to A1–L1.
- `app/Services/AuditLogger.php` and `app/Models/AuditLog.php` — canonical organisation-scoped audit writes and reads.
- `app/Http/Controllers/Hr/AuditController.php` — canonical HR audit viewer.
- `app/Domain/Hr/Services/EmployeeImportExportService.php` and `ReportBuilderService.php` — service CSV neutralisation.
- `app/Domain/Hr/Models/HrCalendarEvent.php` and `HrSalaryBand.php` — retained lifecycle roots.
- `app/Domain/Hr/Services/OnboardingService.php` — offboarding assignee and late-asset reconciliation.
- `app/Services/Operations/TimesheetHrSyncService.php` — stable Timesheet ↔ HrTimeEntry identity.
- `app/Domain/Hr/Services/BenefitsService.php` — benefit organisation invariant.
- `app/Domain/Hr/Services/RecruitmentService.php` — interview quorum.
- `app/Http/Controllers/Hr/CandidateController.php` — offer expiry and quorum override endpoints.
- `app/Http/Controllers/Hr/EmployeeProfileController.php` — HR-branded reinvite.
- `app/Http/Controllers/Hr/ApprovalController.php` and leave pages — leave-chain administration.
- `app/Http/Controllers/Hr/SupervisionController.php`, `GoalController.php`, and `AnnouncementController.php` — deferred notifications.
- `app/Services/ShiftStaffEligibilityService.php` and shift create/edit surfaces — licence requirement seam.
- HR payroll/training/feedback/time/calendar React pages — named UI completion.

### Task 1: Establish the execution ledger and clean baseline

**Files:**
- Create: `HR_DEFERRED_BACKLOG_PROGRESS.md`
- Modify: `docs/superpowers/plans/2026-07-11-hr-deferred-audit-backlog.md`

- [x] **Step 1: Create the requirement ledger**

Create rows A1, A2, E1, R1, R2, O1–O4, X1–X2, Q1–Q2, W1–W7, U1–U5, C1, and L1 with status `⬜` and columns for red evidence, green evidence, commit, and notes. Include the baseline release `9eaab3a5` and design commit `d84cd099`.

- [x] **Step 2: Verify generated routes and clean baselines**

Run:

```powershell
php artisan wayfinder:generate
npm test
npm run build
php artisan test tests/Feature/Hr --compact
```

Expected: Vitest 44/44 files and 184/184 tests; build transforms 4,939 modules; HR suite exits zero after the build manifest exists. Record exact counts and any pre-existing warnings without changing application code.

- [x] **Step 3: Commit the ledger**

```powershell
git add HR_DEFERRED_BACKLOG_PROGRESS.md docs/superpowers/plans/2026-07-11-hr-deferred-audit-backlog.md
git commit -m "docs(hr): start deferred backlog execution ledger"
```

### Task 2: Canonical organisation-scoped audit store (A1, A2)

**Files:**
- Create: `database/migrations/2026_07_11_000001_add_organization_id_to_audit_logs.php`
- Create: `tests/Feature/Hr/CanonicalAuditOrganizationTest.php`
- Modify: `app/Models/AuditLog.php`
- Modify: `app/Services/AuditLogger.php`
- Modify: `app/Http/Controllers/Hr/AuditController.php`
- Modify: `app/Http/Controllers/Hr/PayrollExportController.php`
- Delete: `app/Domain/Hr/Models/HrAuditLog.php`
- Delete: `app/Domain/Hr/Services/AuditService.php`

- [x] **Step 1: Write the audit isolation tests**

Add tests that create two organisations and canonical events, then assert:

```php
$this->actingAs($orgOneHr)
    ->get('/hr/settings/audit-log')
    ->assertOk()
    ->assertInertia(fn (Assert $page) => $page
        ->has('logs.data', 1)
        ->where('logs.data.0.organization_id', $orgOne->id));

expect(AuditLog::query()->where('organization_id', $orgTwo->id)->count())->toBe(1);
```

Also test a global `User` auditable with explicit `organization_id` metadata, an actor-derived event, an unresolvable system event that remains null, and payroll profile default demotion metadata.

- [x] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/CanonicalAuditOrganizationTest.php --compact`

Expected: FAIL because `audit_logs.organization_id` does not exist and the HR viewer reads `hr_audit_log`.

- [x] **Step 3: Add the additive migration and model scope**

The migration adds nullable `organization_id` plus `['organization_id', 'created_at']` index, then backfills from `users.organization_id` and `clients.organization_id` with joined updates. Add:

```php
public function scopeForOrganization(Builder $query, int $organizationId): Builder
{
    return $query->where('organization_id', $organizationId);
}
```

- [x] **Step 4: Resolve organisation on every canonical write**

Implement deterministic resolution in `AuditLogger`:

```php
$organizationId = $meta['organization_id']
    ?? $auditable?->getAttribute('organization_id')
    ?? $auditable?->getAttribute('tenant_id')
    ?? $client?->organization_id
    ?? $user?->organization_id;
```

Persist the value and remove `organization_id` from sensitive free-form metadata only if duplicated.

- [x] **Step 5: Switch the HR viewer and payroll demotion audit**

Use `resolveHrTenantIdForUser()` or the module’s existing equivalent to scope `AuditLog`. Derive filters using `distinct()`. When a new default payroll profile demotes old defaults, write one `hr.payroll_export_profile.default_changed` event containing promoted and demoted IDs.

- [x] **Step 6: Remove unused legacy application classes**

Delete the model/service only after `rg -n "HrAuditLog|AuditService" app tests` shows no remaining application caller. Keep the historical table migration.

- [x] **Step 7: Run GREEN and regressions**

Run:

```powershell
php artisan test tests/Feature/Hr/CanonicalAuditOrganizationTest.php tests/Feature/Hr/UserWriteAuditTest.php tests/Feature/Hr/PayrollExportProfileWorkflowTest.php --compact
php -l app/Services/AuditLogger.php
git diff --check
```

Expected: all pass, no cross-organisation rows.

- [x] **Step 8: Update ledger and commit**

```powershell
git add app database/migrations tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "fix(hr): consolidate audit viewer on canonical organization store"
```

### Task 3: Service CSV neutralisation (E1)

**Files:**
- Create: `tests/Feature/Hr/HrServiceCsvInjectionGuardTest.php`
- Modify: `app/Domain/Hr/Services/EmployeeImportExportService.php`
- Modify: `app/Domain/Hr/Services/ReportBuilderService.php`

- [x] **Step 1: Write RED tests**

Export rows containing `=cmd`, `+SUM(1,1)`, `-1+2`, `@HYPERLINK`, tab, and carriage-return prefixes. Assert text cells receive a leading apostrophe while `-42.50` and `123` remain numeric strings.

- [x] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/HrServiceCsvInjectionGuardTest.php --compact`

Expected: FAIL with raw formula-leading cells.

- [x] **Step 3: Reuse the shared trait**

Add `use SanitizesCsvOutput;` to both services and map every output cell through `sanitizeCsvCell((string) $value)`. Preserve values satisfying `is_numeric()` unchanged.

- [x] **Step 4: Audit all HR CSV writers**

Run:

```powershell
rg -n "fputcsv|streamDownload" app/Domain/Hr app/Http/Controllers/Hr
```

For each result, record in the ledger whether it inherits the base-controller trait, uses a local equivalent already covered by tests, or was updated in this slice.

- [x] **Step 5: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/HrServiceCsvInjectionGuardTest.php tests/Feature/Hr/PayrollCsvInjectionGuardTest.php tests/Feature/Hr/PeopleExportTest.php --compact
git add app/Domain/Hr/Services tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "fix(hr): neutralize formulas in service csv exports"
```

### Task 4: Calendar and salary-band retention (R1, R2)

**Files:**
- Create: `database/migrations/2026_07_11_000002_add_archive_fields_to_hr_calendar_events.php`
- Create: `database/migrations/2026_07_11_000003_add_lifecycle_fields_to_hr_salary_bands.php`
- Create: `tests/Feature/Hr/DeferredRetentionLifecycleTest.php`
- Modify: `app/Domain/Hr/Models/HrCalendarEvent.php`
- Modify: `app/Domain/Hr/Models/HrSalaryBand.php`
- Modify: `app/Http/Controllers/Hr/CalendarController.php`
- Modify: `app/Http/Controllers/Hr/CompensationController.php`
- Modify: `app/Domain/Hr/Services/CompensationService.php`
- Modify: `resources/js/pages/hr/compensation/bands.tsx`
- Modify: `resources/js/pages/hr/calendar/index.tsx` and calendar dialogs

- [x] **Step 1: Write RED retention tests**

Assert calendar removal preserves the root, attendee, reminder, attachment row, and private file while excluding the event from active feed. Assert restore returns it. Assert salary-band deactivation preserves historical placement and excludes it from active selectors.

- [x] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/DeferredRetentionLifecycleTest.php --compact`

Expected: FAIL because calendar destroy deletes and salary bands have no lifecycle fields.

- [x] **Step 3: Add migrations and model contracts**

Calendar fields: `archived_at`, `archived_by`, `archive_reason`. Salary band fields: `is_active default true`, `deactivated_at`, `deactivated_by`. Add active/archived scopes and attribution relationships.

- [x] **Step 4: Replace destroy behavior and copy**

Calendar destroy sets archive fields inside a transaction and never deletes attachments/files. Add manager-only restore. Salary-band deactivate/reactivate uses existing compensation-manage permission. Replace Delete wording with Archive/Deactivate and add retained-history explanations.

- [x] **Step 5: Run GREEN, frontend gates, and commit**

```powershell
php artisan test tests/Feature/Hr/DeferredRetentionLifecycleTest.php tests/Feature/Hr/HrCalendarFeedTest.php tests/Feature/Hr/SalaryBandPlacementTest.php --compact
npm run types
npx eslint resources/js/pages/hr/calendar/index.tsx resources/js/pages/hr/compensation/bands.tsx --max-warnings=0
git diff --check
git add app database/migrations resources/js tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(hr): retain calendar events and salary bands"
```

### Task 5: Offboarding identity and reconciliation (O1–O4)

**Requirements:** O1, O2, O3, O4

**Files:**
- Create: `database/migrations/2026_07_11_000004_link_exit_interviews_to_offboarding_tasks.php`
- Create: `app/Domain/Hr/Notifications/ExitInterviewScheduledNotification.php`
- Create: `tests/Feature/Hr/OffboardingDeferredBacklogTest.php`
- Modify: `app/Domain/Hr/Models/HrOffboardingTask.php`
- Modify: `app/Domain/Hr/Models/HrExitInterview.php`
- Modify: `app/Domain/Hr/Services/OnboardingService.php`
- Modify: `app/Domain/Hr/Services/ExitInterviewService.php`
- Modify: `app/Domain/Hr/Services/AssetService.php`
- Modify: `app/Http/Controllers/Hr/ExitInterviewController.php`
- Modify: `app/Http/Controllers/Hr/OffboardingController.php`
- Modify: `resources/js/pages/hr/offboarding/show.tsx`

- [x] **Step 1: Write RED tests**

Cover explicit linkage without title lookup, ambiguous legacy tasks left untouched, future-scheduled notification only, late asset assignment creating one task, returned asset creating none, and assignee fallback order role → manager → actor.

- [x] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/OffboardingDeferredBacklogTest.php --compact`

Expected: FAIL on missing column, notification, reconciliation, and fallback.

- [x] **Step 3: Add the one-to-one link and deterministic backfill**

Add nullable unique `exit_interview_id` on tasks. Backfill only when an interview’s employee has exactly one open unmatched task. Add model relations.

- [x] **Step 4: Remove title matching from new flows**

Pass `offboarding_task_id` from the checklist flow and attach/complete that exact task. Standalone creation attaches when exactly one open unmatched exit-interview task exists for that employee; zero or multiple matches remain unlinked.

- [x] **Step 5: Implement notification, asset reconciliation, and fallback**

Add `OnboardingService::reconcileAssetReturnTask(HrOffboardingChecklist $checklist, HrAsset $asset, int $actorId)` keyed by checklist plus asset assignment identity. Resolve all required task assignees before inserts and throw `ValidationException` if none resolve.

- [x] **Step 6: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/OffboardingDeferredBacklogTest.php tests/Feature/Hr/OffboardingWorkflowTest.php tests/Feature/Hr/ExitInterviewWizardTest.php tests/Feature/Hr/AssetLifecycleTest.php --compact
git add app database/migrations tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(hr): make offboarding links and asset tasks durable"
```

### Task 6: Cross-module time identity and benefit tenancy (X1, X2)

**Files:**
- Create: `tests/Feature/Hr/TimesheetHrIdentityTest.php`
- Create: `tests/Feature/Hr/BenefitsServiceTenantBoundaryTest.php`
- Modify: `app/Services/Operations/TimesheetHrSyncService.php`
- Modify: `app/Domain/Hr/Services/BenefitsService.php`

- [x] **Step 1: Write RED tests**

For a submitted linked `HrTimeEntry`, approve its timesheet and assert:

```php
expect($timesheet->fresh()->hr_time_entry_id)->toBe($entry->id)
    ->and($entry->fresh()->status)->toBe('approved')
    ->and(HrTimeEntry::query()->where('user_id', $worker->id)->count())->toBe(1);
```

Add repeated approval, conflicting link, and cross-organisation tests. Add a benefit mismatch test asserting zero enrolments and zero notifications.

- [x] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/TimesheetHrIdentityTest.php tests/Feature/Hr/BenefitsServiceTenantBoundaryTest.php --compact`

Expected: linked-entry test exposes duplicate identity; benefit mismatch writes a row.

- [x] **Step 3: Lock and reuse the linked time entry**

Inside approval transaction, lock the linked entry; validate worker and tenant; update it in place. Only use the canonical `source_type/source_id` lookup when no valid link exists. Throw a validation error on conflict.

- [x] **Step 4: Add service-boundary benefit guard**

Before `DB::transaction`:

```php
if ((int) $profile->tenant_id !== (int) $plan->tenant_id) {
    throw ValidationException::withMessages([
        'benefit_plan_id' => 'The benefit plan and employee must belong to the same organisation.',
    ]);
}
```

- [x] **Step 5: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/TimesheetHrIdentityTest.php tests/Feature/Hr/BenefitsServiceTenantBoundaryTest.php tests/Feature/Hr/ShiftPayrollBackboneIntegrationTest.php tests/Feature/Hr/BenefitsEnrollmentTest.php --compact
git add app tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "fix(hr): preserve time-entry identity and benefit tenancy"
```

### Task 7: Recruitment expiry and scorecard quorum (Q1, Q2)

**Files:**
- Create: `database/migrations/2026_07_11_000005_add_offer_expiry_actor_fields.php`
- Create: `tests/Feature/Hr/RecruitmentDeferredLifecycleTest.php`
- Modify: `app/Domain/Hr/Models/HrOffer.php`
- Modify: `app/Domain/Hr/Services/RecruitmentService.php`
- Modify: `app/Http/Controllers/Hr/CandidateController.php`
- Modify: `routes/hr.php`
- Modify: `resources/js/pages/hr/recruitment/index.tsx`

- [x] **Step 1: Write RED tests**

Cover forced expiry with required reason, accepted-offer rejection, portal denial, resend token rotation/revival, full interviewer quorum, zero-interviewer block, missing-score block, and audited override.

- [x] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/RecruitmentDeferredLifecycleTest.php --compact`

Expected: routes absent and stage advancement ignores scores.

- [x] **Step 3: Implement force expiry**

Add `expired_by` and `expiry_reason`. Manager action sets `portal_expires_at=now()`, rotates token to null, stamps actor/reason, and audits. Resend generates a new token and clears explicit expiry attribution.

- [x] **Step 4: Implement quorum in the domain service**

Before advancing beyond interview, load the latest relevant interview, unique assigned interviewer IDs, and submitted score interviewer IDs. Block missing IDs. The override path requires a reason and logs `recruitment.scorecard_quorum_overridden` with missing IDs.

- [x] **Step 5: Add UI actions and run GREEN**

Use the existing confirmation/prompt dialog pattern for force expiry and override. Then run:

```powershell
php artisan test tests/Feature/Hr/RecruitmentDeferredLifecycleTest.php tests/Feature/Hr/RecruitmentOfferLifecycleTest.php tests/Feature/Hr/RecruitmentHubTest.php --compact
npm run types
npx eslint resources/js/pages/hr/recruitment/index.tsx --max-warnings=0
```

- [x] **Step 6: Update ledger and commit**

```powershell
git add app database/migrations resources/js routes tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(hr): complete offer expiry and interview quorum"
```

### Task 8: Employee invitations and leave-chain administration (W1, W2)

**Files:**
- Create: `app/Domain/Hr/Notifications/EmployeeInviteNotification.php`
- Create: `tests/Feature/Hr/EmployeeInviteLifecycleTest.php`
- Create: `tests/Feature/Hr/LeaveApprovalChainAdministrationTest.php`
- Modify: `app/Http/Controllers/Hr/EmployeeProfileController.php`
- Modify: `app/Http/Controllers/Hr/ApprovalController.php`
- Modify: `app/Domain/Hr/Models/HrLeaveApprovalChain.php`
- Modify: `resources/js/pages/hr/approvals/chains.tsx`
- Modify: HR routes

- [ ] **Step 1: Write RED tests**

Assert inactive users receive `EmployeeInviteNotification` containing a reset URL, active users receive a validation error and no notification, leave chains are tenant-scoped CRUD/reorder/activate, and native leave approval behavior remains unchanged.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/EmployeeInviteLifecycleTest.php tests/Feature/Hr/LeaveApprovalChainAdministrationTest.php --compact`

- [ ] **Step 3: Implement branded invite**

Generate the same password-reset token securely, but deliver a database/mail HR notification with `type=employee_invite` and `action_url`. Guard `approved_at !== null` before token creation.

- [ ] **Step 4: Add leave-chain administration**

Expose `HrLeaveApprovalChain` separately from generic `HrApprovalChain` in the chains page. Validate tenant users/roles, use transactions for reorder, and guarantee only one active chain per leave type and tenant.

- [ ] **Step 5: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/EmployeeInviteLifecycleTest.php tests/Feature/Hr/LeaveApprovalChainAdministrationTest.php tests/Feature/Hr/ApprovalChainTenantTest.php --compact
npm run types
npx eslint resources/js/pages/hr/approvals/chains.tsx --max-warnings=0
git add app resources/js routes tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(hr): brand employee invites and manage leave chains"
```

### Task 9: Deferred HR notifications and supervision taxonomy (W3–W6)

**Requirements:** W3, W4, W5, W6

**Files:**
- Create: `app/Domain/Hr/Notifications/SupervisionNoteAddedNotification.php`
- Create: `app/Domain/Hr/Notifications/AnnouncementReplyNotification.php`
- Create: `app/Domain/Hr/Notifications/WorkerComplianceExpiryNotification.php`
- Create: `app/Console/Commands/Hr/SendWorkerComplianceExpiryRemindersCommand.php`
- Create: `database/migrations/2026_07_11_000006_add_worker_expiry_reminder_stamps.php`
- Create: `tests/Feature/Hr/DeferredNotificationContractsTest.php`
- Modify: `app/Http/Controllers/Hr/SupervisionController.php`
- Modify: `app/Http/Controllers/Hr/GoalController.php`
- Modify: `app/Http/Controllers/Hr/AnnouncementController.php`
- Modify: `routes/console.php`
- Modify: `resources/js/components/hr/performance-wizards.tsx`
- Delete: `resources/js/components/hr/performance/supervision-dialog.tsx`

- [ ] **Step 1: Write RED notification tests**

Assert visible supervision notes notify the employee once; private notes do not; OKR completion notifies once on transition; announcement replies notify a same-org author but not self; worker expiry reminders are stamped and deduplicated.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/DeferredNotificationContractsTest.php --compact`

- [ ] **Step 3: Wire transitions, not generic updates**

Call notification helpers only after the relevant transaction commits and only when the prior state was not complete/notified. Reuse existing `HrNotificationService::notifyGoalCompleted()`.

- [ ] **Step 4: Expose session taxonomy and remove dead code**

Populate the create wizard from the existing supervision session-type constant/model taxonomy. Run `rg` for the orphan component, remove it only when no import remains, and cover the submitted `session_type` in Vitest/Pest.

- [ ] **Step 5: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/DeferredNotificationContractsTest.php tests/Feature/Hr/SupervisionDialogTest.php tests/Feature/Hr/GoalsOkrHubTest.php tests/Feature/Hr/AnnouncementCommandCenterTest.php tests/Feature/Hr/ComplianceHubActionsTest.php --compact
npm run types
git add app resources/js tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(hr): complete deferred notification loops"
```

### Task 10: Shift licence requirements (W7)

**Files:**
- Create: `database/migrations/2026_07_11_000007_add_licence_requirements_to_shifts.php`
- Create: `app/Services/Eligibility/Rules/RequiredDriverLicenceRule.php`
- Create: `tests/Feature/Hr/ShiftLicenceRequirementSeamTest.php`
- Modify: `app/Models/Shift.php`
- Modify: `app/Http/Controllers/ShiftController.php`
- Modify: `app/Http/Controllers/CalendarController.php`
- Modify: `app/Services/ShiftStaffEligibilityService.php`
- Modify: `resources/js/pages/operations/shifts/components/create-shift-dialog.tsx`
- Modify: `resources/js/pages/operations/shifts/components/shift-detail-dialog.tsx`
- Modify: `resources/js/components/roster/shift-detail-sheet.tsx`

- [ ] **Step 1: Write RED seam tests**

Cover no-requirement compatibility, matching current class/endorsements, missing class, missing endorsement, expired licence, cross-organisation driver data, assignment block, and roster publish block.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/ShiftLicenceRequirementSeamTest.php --compact`

- [ ] **Step 3: Add fields and shared validator**

Add nullable `required_licence_class` and JSON `required_licence_endorsements`. Validate against the HR driver taxonomy. Register `RequiredDriverLicenceRule` in the existing eligibility pipeline; do not duplicate licence records.

- [ ] **Step 4: Add shift UI**

Show optional class and endorsement selectors in create/edit, badges in read contexts, and plain-language eligibility blockers. Preserve payload omission for ordinary shifts.

- [ ] **Step 5: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/ShiftLicenceRequirementSeamTest.php tests/Feature/Hr/DriverEligibilityRosteringHardStopTest.php tests/Feature/Rostering/DriverLicenceEligibilityWarningTest.php --compact
npm run types
npm test -- resources/js/pages/operations/shifts/components/create-shift-dialog.test.tsx
git add app database/migrations resources/js tests HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(rostering): enforce shift licence requirements"
```

### Task 11: Calendar resilience and team audiences (C1)

**Files:**
- Create: `tests/Feature/Hr/HrCalendarResilienceTest.php`
- Modify: `app/Domain/Hr/Services/HrCalendarAggregator.php`
- Modify: `app/Http/Controllers/Hr/CalendarController.php`
- Modify: HR calendar routes
- Modify: `resources/js/components/hr/calendar/event-wizard-dialog.tsx`
- Modify: `resources/js/pages/hr/calendar/index.tsx`

- [ ] **Step 1: Write RED tests**

Assert feed survives each absent optional table, calendar routes carry view middleware, a team audience requires a valid tenant team, foreign team names fail, and active team members can see team events.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/Hr/HrCalendarResilienceTest.php --compact`

- [ ] **Step 3: Add table guards and route defence**

Each optional layer returns an empty collection when its table is absent. Add group-level `permission:hr.calendar.view` while retaining controller manage and tenant checks.

- [ ] **Step 4: Support team audiences**

Accept `audience_type=team` plus required `audience_team`, validate against distinct active `HrEmployeeProfile.team` values for the tenant, and store the team string in `audience_ref`. Visibility resolves through the viewer’s active profile.

- [ ] **Step 5: Run GREEN and commit**

```powershell
php artisan test tests/Feature/Hr/HrCalendarResilienceTest.php tests/Feature/Hr/HrCalendarFeedTest.php tests/Feature/Hr/HrCalendarEventCrudTest.php --compact
npm run types
npx eslint resources/js/components/hr/calendar/event-wizard-dialog.tsx resources/js/pages/hr/calendar/index.tsx --max-warnings=0
git add app resources/js routes tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "fix(hr): harden calendar layers and team audiences"
```

### Task 12: Named HR UI completion (U1–U5)

**Requirements:** U1, U2, U3, U4, U5

**Files:**
- Create: `resources/js/components/hr/payroll-hero.tsx`
- Create: `resources/js/components/hr/training-hero.tsx`
- Create: `resources/js/components/hr/feedback-hero.tsx`
- Create: `resources/js/test/hr-deferred-ui-contracts.test.tsx`
- Modify: `resources/js/pages/hr/payroll/index.tsx`
- Modify: `resources/js/pages/hr/payroll/payslips.tsx`
- Modify: `resources/js/pages/hr/training/catalog.tsx`
- Modify: `resources/js/pages/hr/feedback/index.tsx`
- Modify: `app/Http/Controllers/Hr/TimeTrackingController.php`
- Modify: `resources/js/pages/hr/time/index.tsx`
- Move: `resources/js/components/hr/recruitment/text-prompt-dialog.tsx` → `resources/js/components/hr/text-prompt-dialog.tsx`

- [ ] **Step 1: Write RED UI contracts**

Test server-derived hero counts/links, payroll desktop/mobile action parity, training token usage, feedback deep links, stable `TextPromptDialog` API, and time refresh preserving filters while replacing entries.

- [ ] **Step 2: Run RED**

Run: `npm test -- resources/js/test/hr-deferred-ui-contracts.test.tsx`

Expected: FAIL because the specialised heroes and refresh contract do not exist.

- [ ] **Step 3: Implement shared HR patterns**

Reuse existing `CalendarHero`/`PeopleHero` anatomy, existing responsive table/mobile-card primitives, row context menus, URL query filters, and standard tokens. Do not add a new component system.

- [ ] **Step 4: Move the prompt dialog**

Move the file and update all imports found by:

```powershell
rg -n "recruitment/text-prompt-dialog|TextPromptDialog" resources/js
```

The final search must show only neutral imports.

- [ ] **Step 5: Run GREEN and commit**

```powershell
npm test
npm run types
npx eslint resources/js/components/hr resources/js/pages/hr/payroll resources/js/pages/hr/training/catalog.tsx resources/js/pages/hr/feedback/index.tsx --max-warnings=0
npm run build
npx vite build --ssr
git add app resources/js tests/Feature/Hr HR_DEFERRED_BACKLOG_PROGRESS.md
git commit -m "feat(hr): complete deferred hub and table polish"
```

### Task 13: Ledger truth, complete verification, and closeout (L1)

**Files:**
- Modify: `HR_DEFERRED_BACKLOG_PROGRESS.md`
- Modify: `HR_AUDIT_FIX_PROGRESS.md`
- Modify: `HR_CLOSEOUT_PROGRESS.md`

- [ ] **Step 1: Classify every historical open observation**

Search both source ledgers for `Open:`, `deferred`, `follow-up`, `Decision`, and partial markers. Append a matrix mapping each observation to a requirement commit, stale evidence, or an approved closed boundary. Leave no unclassified open item.

- [ ] **Step 2: Run migration verification**

On the testing database, run migrations from release baseline forward, rollback the new batch, and migrate forward again. Verify additive backfills are idempotent and no retained rows/files disappear.

- [ ] **Step 3: Run complete backend verification**

```powershell
php artisan test tests/Feature/Hr --compact
php artisan test tests/Feature/Timesheets tests/Feature/Rostering tests/Unit/Services/ShiftStaffEligibilityServiceTest.php --compact
```

Expected: zero failures. Record tests, assertions, warnings, and duration exactly.

- [ ] **Step 4: Run complete frontend and static verification**

```powershell
npm test
npm run types
$changed = git diff --name-only main...HEAD -- '*.ts' '*.tsx'
if ($changed) { npx eslint $changed --max-warnings=0 }
npm run build
npx vite build --ssr
git diff --check
```

Expected: zero failures/warnings for changed files, successful client and SSR builds.

- [ ] **Step 5: Browser verification**

Verify every changed surface in the isolated preview: audit log, calendar archive/team audience, salary bands, offboarding, recruitment, leave-chain settings, payroll, training, feedback, supervision, time refresh, and shift licence requirements. Record URL, actor/permission, action, visible result, console errors, and failed network requests.

- [ ] **Step 6: Perform the requirement-by-requirement completion audit**

For A1 through L1, cite the authoritative test, source, migration, UI proof, and commit. Any missing or indirect evidence keeps the row incomplete and must be fixed before closing.

- [ ] **Step 7: Commit closeout**

```powershell
git add HR_DEFERRED_BACKLOG_PROGRESS.md HR_AUDIT_FIX_PROGRESS.md HR_CLOSEOUT_PROGRESS.md
git commit -m "docs(hr): close deferred audit backlog"
```

Do not merge, push, deploy, or change `main` without separate user authorisation.
