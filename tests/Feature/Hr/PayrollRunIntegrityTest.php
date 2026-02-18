<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Domain\Hr\Services\PayrollExportService;
use App\Models\Client;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Validation\ValidationException;

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

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP90001',
        'work_email' => "worker-{$this->staff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'hourly_rate' => '30.00',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('payroll run items apply multi rate rules per timesheet instead of single rule for entire period', function () {
    $client = Client::factory()->create();

    HrPayRateRule::query()->create([
        'tenant_id' => 1,
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
        'tenant_id' => 1,
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
    $items = $service->getRunItems(1, now()->subWeek()->startOfDay(), now()->endOfDay());

    expect($items)->toHaveKey($this->staff->id);
    expect((float) $items[$this->staff->id]['gross_pay'])->toBe(720.00);
    expect($items[$this->staff->id]['rate_breakdown']['line_items'])->toHaveCount(2);
});

test('payroll run cannot be locked when linked timesheets fail validation', function () {
    $client = Client::factory()->create();

    $timesheet = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
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
    $run = $service->createRun(1, now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);

    // Simulate post-run mutation that should block lock.
    $timesheet->update(['status' => 'draft']);

    expect(fn () => $service->lockRun($run->fresh(), $this->hr->id))
        ->toThrow(ValidationException::class);

    $run->refresh();
    expect($run->status)->toBe('draft');
    expect($run->validation_errors)->not->toBeEmpty();
});
