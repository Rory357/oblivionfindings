<?php

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $staffRole = Role::query()->where('name', 'support_worker')->first();
    if ($staffRole) {
        $this->staff->roles()->syncWithoutDetaching([$staffRole->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-HR-2001',
        'work_email' => "hr-{$this->hr->id}@example.test",
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(2)->toDateString(),
        'is_active' => true,
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-STF-2002',
        'work_email' => "staff-{$this->staff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(1)->toDateString(),
        'is_active' => true,
    ]);
});

test('hr cases index exposes case_type and severity filter contract', function () {
    HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-71001',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'high',
        'status' => 'open',
        'title' => 'Late handover',
        'description' => 'Late handover occurred twice this week.',
        'reported_by' => $this->hr->id,
        'assigned_to' => $this->hr->id,
        'opened_at' => now()->subDay(),
        'created_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/cases');
    $response->assertOk();

    expect($response->inertiaProps('cases.data.0.case_type'))->toBe('disciplinary');
    expect($response->inertiaProps('cases.data.0.severity'))->toBe('high');
    expect($response->inertiaProps('filters.case_type'))->toBeNull();
    expect($response->inertiaProps('filters.severity'))->toBeNull();
    expect($response->inertiaProps('filters.q'))->toBeNull();
    expect($response->inertiaProps('filters.sla_window'))->toBeNull();
    expect($response->inertiaProps('summary.open_cases'))->toBe(1);
    expect($response->inertiaProps('summary.unassigned_open_cases'))->toBe(0);
});

test('hr cases index supports search by case number title and subject name', function () {
    HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-71011',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'high',
        'status' => 'open',
        'title' => 'Medication chart concern',
        'description' => 'Chart documentation issue.',
        'reported_by' => $this->hr->id,
        'opened_at' => now()->subDay(),
        'created_by' => $this->hr->id,
    ]);

    $anotherStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        'name' => 'Taylor Caseperson',
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $anotherStaff->id,
        'employee_number' => 'EMP-STF-2003',
        'work_email' => "staff-{$anotherStaff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonths(8)->toDateString(),
        'is_active' => true,
    ]);

    HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-71012',
        'user_id' => $anotherStaff->id,
        'case_type' => 'complaint',
        'severity' => 'low',
        'status' => 'open',
        'title' => 'Positive feedback follow-up',
        'description' => 'No issue requiring action.',
        'reported_by' => $this->hr->id,
        'opened_at' => now()->subDays(2),
        'created_by' => $this->hr->id,
    ]);

    $byNumber = $this->actingAs($this->hr)->get('/hr/cases?q=71011');
    $byNumber->assertOk();
    expect($byNumber->inertiaProps('cases.data'))->toHaveCount(1);
    expect($byNumber->inertiaProps('cases.data.0.case_number'))->toBe('HR-71011');

    $byTitle = $this->actingAs($this->hr)->get('/hr/cases?q=feedback');
    $byTitle->assertOk();
    expect($byTitle->inertiaProps('cases.data'))->toHaveCount(1);
    expect($byTitle->inertiaProps('cases.data.0.case_number'))->toBe('HR-71012');

    $bySubject = $this->actingAs($this->hr)->get('/hr/cases?q=taylor');
    $bySubject->assertOk();
    expect($bySubject->inertiaProps('cases.data'))->toHaveCount(1);
    expect($bySubject->inertiaProps('cases.data.0.case_number'))->toBe('HR-71012');
});

test('hr case show exposes timeline and disciplinary action payload used by ui', function () {
    $case = HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-71002',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'medium',
        'status' => 'open',
        'title' => 'Medication charting concern',
        'description' => 'Gaps identified in charting documentation.',
        'reported_by' => $this->hr->id,
        'assigned_to' => $this->hr->id,
        'opened_at' => now()->subDays(2),
        'created_by' => $this->hr->id,
    ]);

    HrCaseEvent::query()->create([
        'case_id' => $case->id,
        'event_type' => 'meeting',
        'title' => 'Initial meeting',
        'description' => 'Fact-finding meeting held.',
        'occurred_at' => now()->subDay(),
        'created_by' => $this->hr->id,
    ]);

    HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated documentation gaps.',
        'good_faith_checklist' => [
            'allegation_communicated' => true,
            'opportunity_to_respond' => true,
        ],
        'created_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get("/hr/cases/{$case->id}");
    $response->assertOk();

    expect($response->inertiaProps('case.case_number'))->toBe('HR-71002');
    expect($response->inertiaProps('case.case_type'))->toBe('disciplinary');
    expect($response->inertiaProps('timeline.0.title'))->toBe('Initial meeting');
    expect($response->inertiaProps('case.disciplinary_actions.0.stage'))->toBe('response_period');
    expect($response->inertiaProps('case.disciplinary_actions.0.action_type'))->toBe('written_warning');
});

test('hr cases index supports disciplinary sla filter windows and summary counts', function () {
    $overdueCase = HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-72001',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'high',
        'status' => 'open',
        'title' => 'Overdue response case',
        'description' => 'Response period has exceeded deadline.',
        'reported_by' => $this->hr->id,
        'assigned_to' => $this->hr->id,
        'opened_at' => now()->subDays(2),
        'created_by' => $this->hr->id,
    ]);

    $dueSoonCase = HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-72002',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'medium',
        'status' => 'open',
        'title' => 'Due soon response case',
        'description' => 'Response deadline is within 24 hours.',
        'reported_by' => $this->hr->id,
        'assigned_to' => $this->hr->id,
        'opened_at' => now()->subDay(),
        'created_by' => $this->hr->id,
    ]);

    HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $overdueCase->id,
        'employee_user_id' => $this->staff->id,
        'investigator_user_id' => $this->hr->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Deadline missed for formal response.',
        'response_deadline' => now()->subHours(4),
        'created_by' => $this->hr->id,
    ]);

    HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $dueSoonCase->id,
        'employee_user_id' => $this->staff->id,
        'investigator_user_id' => $this->hr->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Deadline approaching.',
        'response_deadline' => now()->addHours(8),
        'created_by' => $this->hr->id,
    ]);

    $filtered = $this->actingAs($this->hr)->get('/hr/cases?sla_window=overdue');
    $filtered->assertOk();

    expect($filtered->inertiaProps('filters.sla_window'))->toBe('overdue');
    expect($filtered->inertiaProps('cases.data'))->toHaveCount(1);
    expect($filtered->inertiaProps('cases.data.0.case_number'))->toBe('HR-72001');
    expect($filtered->inertiaProps('summary.disciplinary_active'))->toBe(2);
    expect($filtered->inertiaProps('summary.disciplinary_sla_overdue'))->toBe(1);
    expect($filtered->inertiaProps('summary.disciplinary_sla_due_24h'))->toBe(1);
});

test('hr case timeline enforces event visibility rules for non-managers', function () {
    $case = HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-73001',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'medium',
        'status' => 'open',
        'title' => 'Visibility scope case',
        'description' => 'Used to assert timeline visibility behavior.',
        'reported_by' => $this->hr->id,
        'opened_at' => now()->subDays(2),
        'created_by' => $this->hr->id,
    ]);

    HrCaseEvent::query()->create([
        'case_id' => $case->id,
        'event_type' => 'note',
        'title' => 'Internal HR note',
        'description' => 'Only HR managers should see this.',
        'occurred_at' => now()->subHours(3),
        'visibility' => 'internal',
        'created_by' => $this->hr->id,
    ]);

    HrCaseEvent::query()->create([
        'case_id' => $case->id,
        'event_type' => 'meeting',
        'title' => 'Restricted manager update',
        'description' => 'Visible to assigned/reported/subject users.',
        'occurred_at' => now()->subHours(2),
        'visibility' => 'restricted',
        'created_by' => $this->hr->id,
    ]);

    HrCaseEvent::query()->create([
        'case_id' => $case->id,
        'event_type' => 'email',
        'title' => 'Full visibility update',
        'description' => 'Visible to all users with case view access.',
        'occurred_at' => now()->subHour(),
        'visibility' => 'full',
        'created_by' => $this->hr->id,
    ]);

    $participant = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $observer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportWorkerRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportWorkerRole) {
        $participant->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
        $observer->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $participant->id,
        'employee_number' => 'EMP-STF-2201',
        'work_email' => "participant-{$participant->id}@example.test",
        'position_title' => 'Team Lead',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $observer->id,
        'employee_number' => 'EMP-STF-2202',
        'work_email' => "observer-{$observer->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    // hr_cases.is_confidential defaults to true, so grant the observer explicit
    // access via the access_list; the participant already qualifies as
    // assigned_to. This keeps the timeline event-visibility assertions running
    // under the confidential-case access gate.
    $case->update([
        'assigned_to' => $participant->id,
        'access_list' => [$observer->id],
        'updated_by' => $this->hr->id,
    ]);

    $viewCasesPermission = Permission::query()->where('key', 'hr.cases.view')->firstOrFail();

    $participant->permissionOverrides()->syncWithoutDetaching([
        $viewCasesPermission->id => ['allowed' => true],
    ]);
    $observer->permissionOverrides()->syncWithoutDetaching([
        $viewCasesPermission->id => ['allowed' => true],
    ]);

    $participantResponse = $this->actingAs($participant)->get("/hr/cases/{$case->id}");
    $participantResponse->assertOk();

    $participantTitles = collect($participantResponse->inertiaProps('timeline'))
        ->pluck('title')
        ->all();

    expect($participantTitles)->not->toContain('Internal HR note');
    expect($participantTitles)->toContain('Restricted manager update');
    expect($participantTitles)->toContain('Full visibility update');

    $observerResponse = $this->actingAs($observer)->get("/hr/cases/{$case->id}");
    $observerResponse->assertOk();

    $observerTitles = collect($observerResponse->inertiaProps('timeline'))
        ->pluck('title')
        ->all();

    expect($observerTitles)->not->toContain('Internal HR note');
    expect($observerTitles)->not->toContain('Restricted manager update');
    expect($observerTitles)->toContain('Full visibility update');
});
