<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Domain\Hr\Services\PayrollExportService;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->site = Site::factory()->create([
        'name' => 'Payroll integrity Site',
    ]);

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

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    HrEmployeeProfile::query()->create([
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP90001',
        'work_email' => "worker-{$this->staff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'hourly_rate' => '30.00',
        'primary_site_id' => $this->site->id,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('payroll run items apply multi rate rules per timesheet instead of single rule for entire period', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    HrPayRateRule::query()->create([
        'name' => 'Default Support Rule',
        'is_active' => true,
        'priority' => 100,
        'position_role' => 'support_worker',
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 1.50,
        'public_holiday_multiplier' => 1.50,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    HrPayRateRule::query()->create([
        'name' => 'Public Holiday Rule',
        'is_active' => true,
        'priority' => 10,
        'position_role' => 'support_worker',
        'applies_on_public_holiday' => true,
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 2.00,
        'public_holiday_multiplier' => 2.00,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDays(2)->toDateString(),
        'starts_at' => now()->subDays(2)->setTime(9, 0),
        'ends_at' => now()->subDays(2)->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDays(2),
        'approved_at' => now()->subDays(1),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => true,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $items = $service->getRunItems(now()->subWeek()->startOfDay(), now()->endOfDay());

    expect($items)->toHaveKey($this->staff->id);
    expect((float) $items[$this->staff->id]['gross_pay'])->toBe(720.00);
    expect($items[$this->staff->id]['rate_breakdown']['line_items'])->toHaveCount(2);
});

test('payroll run cannot be locked when linked timesheets fail validation', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    $timesheet = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);

    // Simulate out-of-band corruption that the immutable source digest must
    // still detect even if a caller bypasses the model-level claim guard.
    DB::table('timesheets')->where('id', $timesheet->id)->update(['status' => 'draft']);

    expect(fn () => $service->lockRun($run->fresh(), $this->hr->id))
        ->toThrow(ValidationException::class);

    $run->refresh();
    expect($run->status)->toBe('draft');
    expect($run->validation_errors)->not->toBeEmpty();
});

test('locking a run cascades linked approved timesheets to paid', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    HrPayRateRule::query()->create([
        'name' => 'Default Support Rule',
        'is_active' => true,
        'priority' => 100,
        'position_role' => 'support_worker',
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 1.50,
        'public_holiday_multiplier' => 1.50,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    $timesheet = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);
    $service->lockRun($run->fresh(), $this->hr->id);

    $timesheet->refresh();
    expect($timesheet->status)->toBe('paid');
    expect($timesheet->payroll_reference)->toBe("hr-payroll-run:{$run->id}");
    expect($timesheet->exported_to_payroll_at)->not->toBeNull();
});

test('paid cascade is idempotent', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    HrPayRateRule::query()->create([
        'name' => 'Default Support Rule',
        'is_active' => true,
        'priority' => 100,
        'position_role' => 'support_worker',
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 1.50,
        'public_holiday_multiplier' => 1.50,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);
    $service->lockRun($run->fresh(), $this->hr->id);

    // Re-running the cascade on an already-paid run marks nothing new and does not error.
    $newlyPaid = $service->markRunTimesheetsPaid($run->fresh());
    expect($newlyPaid)->toBe(0);
});

test('timesheets outside the run period are not marked paid', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    HrPayRateRule::query()->create([
        'name' => 'Default Support Rule',
        'is_active' => true,
        'priority' => 100,
        'position_role' => 'support_worker',
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 1.50,
        'public_holiday_multiplier' => 1.50,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    $inPeriod = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $outOfPeriod = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subMonth()->toDateString(),
        'starts_at' => now()->subMonth()->setTime(9, 0),
        'ends_at' => now()->subMonth()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subMonth(),
        'approved_at' => now()->subMonth()->addHour(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);
    $service->lockRun($run->fresh(), $this->hr->id);

    $inPeriod->refresh();
    expect($inPeriod->status)->toBe('paid');

    $outOfPeriod->refresh();
    expect($outOfPeriod->status)->toBe('approved');
    expect($outOfPeriod->payroll_reference)->toBeNull();
    expect($outOfPeriod->exported_to_payroll_at)->toBeNull();
});

test('paid cascade bypasses the workflow guard for a pre-stamped approved timesheet', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    HrPayRateRule::query()->create([
        'name' => 'Default Support Rule',
        'is_active' => true,
        'priority' => 100,
        'position_role' => 'support_worker',
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 1.50,
        'public_holiday_multiplier' => 1.50,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    $timesheet = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    // Pre-stamp it as the legacy operations export would have, without tripping the guard.
    $timesheet->forceFill([
        'exported_to_payroll_at' => now(),
        'payroll_reference' => 'operations-payroll-export:99',
    ])->saveQuietly();

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);

    // Must not throw despite the pre-existing payroll stamp.
    $service->lockRun($run->fresh(), $this->hr->id);

    $timesheet->refresh();
    expect($timesheet->status)->toBe('paid');
    expect($timesheet->payroll_reference)->toBe("hr-payroll-run:{$run->id}");
});

// Gap 4.1 guard: locking a run must generate payslips so the GL journal posted
// right after (PostPayrollJournalJob) has payslips to read — otherwise the run
// stays locked with a null journal_id and the job dies to failed_jobs.
test('locking a run generates payslips so the GL journal has data to read', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    HrPayRateRule::query()->create([
        'name' => 'Default Support Rule',
        'is_active' => true,
        'priority' => 100,
        'position_role' => 'support_worker',
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 1.50,
        'public_holiday_multiplier' => 1.50,
        'sleepover_flat_rate' => 0,
        'on_call_hourly_rate' => 0,
        'created_by' => $this->hr->id,
    ]);

    Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);

    expect($run->fresh()->payslips()->count())->toBe(0);

    $service->lockRun($run->fresh(), $this->hr->id);

    expect($run->fresh()->payslips()->count())->toBeGreaterThan(0);
});
