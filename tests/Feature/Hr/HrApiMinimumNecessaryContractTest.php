<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->apiSite = Site::factory()->create(['name' => 'Minimum API Site']);
    $this->apiViewer = minimumApiStaff('Minimum API Viewer', $this->apiSite);
    $role = Role::query()->create([
        'name' => 'minimum_api_viewer',
        'label' => 'Minimum API viewer',
        'type' => 'custom',
        'level' => 60,
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key', [
        'hr.employees.viewAny',
        'hr.leave.viewAny',
        'hr.leave.manage',
        'hr.compliance.view',
        'timesheets.viewAny',
        'hr.payroll.view',
    ])->pluck('id')->all());
    $this->apiViewer->roles()->sync([$role->id]);
});

function minimumApiStaff(string $name, Site $site, array $profileOverrides = []): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-MIN-'.$user->id,
        'work_email' => "minimum-{$user->id}@work.example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => today()->subYear(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);

    return $user;
}

function expectMinimumApiKeys(array $payload, array $keys): void
{
    expect(array_keys($payload))->toEqualCanonicalizing($keys);
}

test('authenticated HR API returns explicit minimum necessary records only', function (): void {
    $subject = minimumApiStaff('Minimum API Subject', $this->apiSite, [
        'date_of_birth' => '1990-01-01',
        'personal_email' => 'private-person@example.test',
        'personal_phone' => 'PRIVATE-PHONE-SENTINEL',
        'home_address' => 'PRIVATE-ADDRESS-SENTINEL',
        'hourly_rate' => '91.25',
        'annual_salary' => '190000',
        'bank_account' => 'PRIVATE-BANK-SENTINEL',
        'ird_number' => 'PRIVATE-TAX-SENTINEL',
        'tax_code' => 'PRIVATE-TAX-CODE',
        'emergency_contacts' => [['name' => 'PRIVATE-CONTACT-SENTINEL']],
        'notes' => 'PRIVATE-NOTES-SENTINEL',
        'restricted_notes' => 'RESTRICTED-NOTES-SENTINEL',
    ]);
    $profile = $subject->hrEmployeeProfile()->firstOrFail();
    $leave = HrLeaveRequest::factory()->create([
        'user_id' => $subject->id,
        'leave_type' => 'annual',
        'status' => 'pending',
        'reason' => 'Approved API leave reason',
        'supporting_doc_path' => 'private/leave/sentinel.pdf',
        'created_by' => $subject->id,
    ]);
    HrLeaveBalance::factory()->create([
        'user_id' => $subject->id,
        'leave_type' => 'annual',
        'year' => 2026,
        'notes' => 'PRIVATE-BALANCE-NOTES',
    ]);
    HrPosition::factory()->create([
        'code' => 'MIN-API-POSITION',
        'title' => 'Minimum API Position',
        'description' => 'PRIVATE-POSITION-DESCRIPTION',
        'requirements' => 'PRIVATE-POSITION-REQUIREMENT',
        'responsibilities' => 'PRIVATE-POSITION-RESPONSIBILITY',
        'created_by' => $this->apiViewer->id,
    ]);
    $requirement = HrComplianceRequirement::factory()->create([
        'code' => 'MIN-API-COMPLIANCE',
        'name' => 'Minimum API compliance',
    ]);
    HrStaffComplianceStatus::query()->create([
        'user_id' => $subject->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
        'evidence_path' => 'private/compliance/sentinel.pdf',
        'exemption_reason' => 'PRIVATE-EXEMPTION-REASON',
        'notes' => 'PRIVATE-COMPLIANCE-NOTES',
    ]);
    HrTimeEntry::factory()->create([
        'user_id' => $subject->id,
        'site_id' => $this->apiSite->id,
        'entry_date' => '2026-07-02',
        'clock_in' => '2026-07-02 08:00:00',
        'clock_out' => '2026-07-02 16:00:00',
        'status' => 'approved',
        'notes' => 'PRIVATE-TIME-NOTES',
        'original_values' => ['PRIVATE-ORIGINAL-VALUE'],
        'created_by' => $this->apiViewer->id,
    ]);
    $run = HrPayrollRun::factory()->create([
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-15',
        'status' => 'draft',
        'export_path' => 'private/payroll/sentinel.csv',
        'notes' => 'PRIVATE-PAYROLL-NOTES',
        'validation_errors' => ['PRIVATE-PAYROLL-ERROR'],
        'gl_error' => 'PRIVATE-GL-ERROR',
        'created_by' => $this->apiViewer->id,
    ]);
    HrPayrollRunItem::query()->create([
        'payroll_run_id' => $run->id,
        'user_id' => $subject->id,
    ]);

    $employees = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson('/api/hr/employees?q=Minimum%20API%20Subject')
        ->assertOk()->assertJsonCount(1, 'data');
    expectMinimumApiKeys($employees->json('data.0'), [
        'id', 'user_id', 'employee_number', 'work_email', 'work_phone',
        'position_title', 'position_role', 'employment_type', 'contract_type',
        'start_date', 'end_date', 'is_active', 'primary_site_id', 'secondary_site_ids',
        'position_id', 'manager_user_id', 'department', 'department_id', 'team',
        'preferred_name', 'user', 'primary_site',
    ]);
    expectMinimumApiKeys($employees->json('data.0.user'), ['id', 'name', 'email']);
    expectMinimumApiKeys($employees->json('data.0.primary_site'), ['id', 'name']);

    $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson("/api/hr/employees/{$profile->id}")
        ->assertOk()
        ->assertJsonPath('employee_number', $profile->employee_number);

    $leaveResponse = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson("/api/hr/leave/requests?user_id={$subject->id}")
        ->assertOk()->assertJsonCount(1, 'data');
    expectMinimumApiKeys($leaveResponse->json('data.0'), [
        'id', 'user_id', 'leave_type', 'period', 'starts_at', 'ends_at',
        'hours_requested', 'reason', 'reason_restricted', 'has_doc', 'status',
        'submitted_at', 'approval_due_at', 'reviewed_by', 'reviewed_at',
        'escalation_level', 'escalated_at', 'user', 'reviewer',
    ]);
    expect($leaveResponse->json('data.0.id'))->toBe($leave->id);

    $balanceResponse = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$subject->id}")
        ->assertOk()->assertJsonCount(1);
    expectMinimumApiKeys($balanceResponse->json('0'), [
        'id', 'user_id', 'leave_type', 'balance_hours', 'accrued_hours',
        'used_hours', 'pending_hours', 'year', 'source', 'last_synced_at',
    ]);

    $positionResponse = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson('/api/hr/positions')
        ->assertOk()->assertJsonCount(1, 'data');
    expectMinimumApiKeys($positionResponse->json('data.0'), [
        'id', 'title', 'code', 'department', 'team', 'summary', 'employment_type',
        'fte', 'headcount_budget', 'current_headcount', 'vacancies',
        'reports_to_position_id', 'is_active',
    ]);

    $complianceResponse = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson('/api/hr/compliance/status')
        ->assertOk()->assertJsonCount(1, 'data');
    expectMinimumApiKeys($complianceResponse->json('data.0'), [
        'id', 'user_id', 'requirement_id', 'status', 'evidence_type',
        'evidence_category', 'valid_from', 'expires_at', 'is_exempt',
        'exempted_until', 'last_checked_at', 'next_check_at', 'user', 'requirement',
    ]);

    $timeResponse = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson('/api/hr/time/entries?date_from=2026-07-01&date_to=2026-07-31')
        ->assertOk()->assertJsonCount(1, 'data');
    expectMinimumApiKeys($timeResponse->json('data.0'), [
        'id', 'user_id', 'shift_id', 'site_id', 'client_id', 'entry_date',
        'clock_in', 'clock_out', 'break_minutes', 'total_hours', 'entry_type',
        'status', 'pay_type', 'is_sleepover', 'is_on_call', 'is_public_holiday',
        'mileage_km', 'break_compliance_met', 'approved_by', 'approved_at', 'user',
    ]);

    $payrollResponse = $this->actingAs($this->apiViewer, 'sanctum')
        ->getJson('/api/hr/payroll/runs')
        ->assertOk()->assertJsonCount(1, 'data');
    expectMinimumApiKeys($payrollResponse->json('data.0'), [
        'id', 'period_start', 'period_end', 'status', 'locked_at', 'locked_by',
        'exported_at', 'exported_by', 'export_format', 'total_hours', 'total_gross',
        'total_staff', 'journal_id', 'gl_posted_at', 'net_paid_at', 'created_by', 'creator',
    ]);

    $serialized = implode('|', [
        $employees->getContent(),
        $leaveResponse->getContent(),
        $balanceResponse->getContent(),
        $positionResponse->getContent(),
        $complianceResponse->getContent(),
        $timeResponse->getContent(),
        $payrollResponse->getContent(),
    ]);
    foreach ([
        'PRIVATE-PHONE-SENTINEL', 'PRIVATE-ADDRESS-SENTINEL', 'PRIVATE-BANK-SENTINEL',
        'PRIVATE-TAX-SENTINEL', 'PRIVATE-CONTACT-SENTINEL', 'PRIVATE-NOTES-SENTINEL',
        'RESTRICTED-NOTES-SENTINEL', 'private/leave/sentinel.pdf',
        'PRIVATE-BALANCE-NOTES', 'PRIVATE-POSITION-DESCRIPTION',
        'PRIVATE-POSITION-REQUIREMENT', 'PRIVATE-POSITION-RESPONSIBILITY',
        'private/compliance/sentinel.pdf', 'PRIVATE-EXEMPTION-REASON',
        'PRIVATE-COMPLIANCE-NOTES', 'PRIVATE-TIME-NOTES', 'PRIVATE-ORIGINAL-VALUE',
        'private/payroll/sentinel.csv', 'PRIVATE-PAYROLL-NOTES',
        'PRIVATE-PAYROLL-ERROR', 'PRIVATE-GL-ERROR',
    ] as $sentinel) {
        expect($serialized)->not->toContain($sentinel);
    }
});

test('authenticated HR API rejects unbounded and malformed filters', function (): void {
    foreach ([
        '/api/hr/employees?per_page=101',
        '/api/hr/employees?active=not-a-boolean',
        '/api/hr/leave/requests?user_id=not-a-number',
        '/api/hr/positions?per_page=0',
        '/api/hr/compliance/status?status[]=compliant',
        '/api/hr/time/entries?date_from=2026-07-10&date_to=2026-07-01',
        '/api/hr/payroll/runs?status[]=draft',
    ] as $endpoint) {
        $this->actingAs($this->apiViewer, 'sanctum')
            ->getJson($endpoint)
            ->assertUnprocessable();
    }
});
