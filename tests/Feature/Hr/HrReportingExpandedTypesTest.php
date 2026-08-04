<?php

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Services\HrReportingService;
use App\Models\User;

beforeEach(function () {
    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP98001',
        'work_email' => "worker-{$this->worker->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonths(6)->toDateString(),
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrCandidate::query()->create([
        'first_name' => 'Ari',
        'last_name' => 'Candidate',
        'personal_email' => 'ari.candidate@example.test',
        'status' => 'hired',
        'source' => 'referral',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrCandidate::query()->create([
        'first_name' => 'Blair',
        'last_name' => 'Candidate',
        'personal_email' => 'blair.candidate@example.test',
        'status' => 'interview',
        'source' => 'job_board',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrPayrollRun::query()->create([
        'period_start' => now()->subWeeks(2)->toDateString(),
        'period_end' => now()->subWeek()->toDateString(),
        'status' => 'locked',
        'locked_at' => now()->subDays(5),
        'total_hours' => 320.5,
        'total_gross' => 9800.75,
        'total_staff' => 12,
        'created_by' => $this->hr->id,
    ]);

    HrPayrollRun::query()->create([
        'period_start' => now()->subWeek()->toDateString(),
        'period_end' => now()->toDateString(),
        'status' => 'exported',
        'locked_at' => now()->subDays(2),
        'exported_at' => now()->subDay(),
        'total_hours' => 301.0,
        'total_gross' => 9150.0,
        'total_staff' => 11,
        'created_by' => $this->hr->id,
    ]);

    HrLeaveRequest::query()->create([
        'user_id' => $this->worker->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(11),
        'hours_requested' => 16,
        'status' => 'pending',
        'submitted_at' => now()->subDays(3),
        'approval_due_at' => now()->subDay(),
        'created_by' => $this->worker->id,
    ]);

    HrLeaveRequest::query()->create([
        'user_id' => $this->worker->id,
        'leave_type' => 'sick',
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(9),
        'hours_requested' => 8,
        'status' => 'approved',
        'submitted_at' => now()->subDays(11),
        'reviewed_at' => now()->subDays(9),
        'created_by' => $this->worker->id,
    ]);

    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $this->profile->id,
        'template_key' => 'default:all',
        'status' => 'completed',
        'started_at' => now()->subDays(15),
        'completed_at' => now()->subDays(5),
        'due_date' => now()->subDays(3)->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    HrOffboardingChecklist::query()->create([
        'employee_profile_id' => $this->profile->id,
        'template_key' => 'offboarding:default',
        'status' => 'in_progress',
        'started_at' => now()->subDays(7),
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
    ]);
});

test('reporting service returns extended report types with expected metrics', function () {
    $service = app(HrReportingService::class);
    $dateFrom = now()->subMonth()->toDateString();
    $dateTo = now()->toDateString();

    $types = $service->reportTypes();
    expect($types)->toHaveKeys([
        'recruitment_funnel',
        'payroll_overview',
        'leave_sla',
        'onboarding_completion',
    ]);

    $recruitment = $service->generate('recruitment_funnel', $dateFrom, $dateTo);
    expect($recruitment['report_type'])->toBe('recruitment_funnel');
    expect(data_get($recruitment, 'data.total_candidates'))->toBe(2);
    expect(data_get($recruitment, 'data.hired_candidates'))->toBe(1);

    $payroll = $service->generate('payroll_overview', $dateFrom, $dateTo);
    expect($payroll['report_type'])->toBe('payroll_overview');
    expect(data_get($payroll, 'data.total_runs'))->toBe(2);
    expect((float) data_get($payroll, 'data.total_gross'))->toBeGreaterThan(0);

    $leaveSla = $service->generate('leave_sla', $dateFrom, $dateTo);
    expect($leaveSla['report_type'])->toBe('leave_sla');
    expect(data_get($leaveSla, 'data.total_requests'))->toBe(2);
    expect(data_get($leaveSla, 'data.pending_overdue'))->toBe(1);

    $onboarding = $service->generate('onboarding_completion', $dateFrom, $dateTo);
    expect($onboarding['report_type'])->toBe('onboarding_completion');
    expect(data_get($onboarding, 'data.onboarding_total'))->toBe(1);
    expect(data_get($onboarding, 'data.offboarding_total'))->toBe(1);
});
