<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PayrollExportService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    if ($hrRole = Role::query()->where('name', 'hr')->first()) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    if ($supportRole = Role::query()->where('name', 'support_worker')->first()) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-MANUAL-001',
        'work_email' => "manual-worker-{$this->staff->id}@example.test",
        'position_role' => 'support_worker',
        'hourly_rate' => '30.00',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('manual HR time entries store worker local input in UTC and create payroll-ready operations timesheets', function () {
    $entry = app(TimeTrackingService::class)->createManualEntry($this->hr, [
        'user_id' => $this->staff->id,
        'clock_in' => '2026-06-15T09:00',
        'clock_out' => '2026-06-15T17:00',
        'break_minutes' => 30,
        'notes' => 'Manual correction from HR',
    ]);

    $rawEntry = DB::table('hr_time_entries')->where('id', $entry->id)->first();
    expect((string) $rawEntry->entry_date)->toBe('2026-06-15')
        ->and(substr((string) $rawEntry->clock_in, 0, 19))->toBe('2026-06-14 21:00:00')
        ->and(substr((string) $rawEntry->clock_out, 0, 19))->toBe('2026-06-15 05:00:00');

    $timesheet = Timesheet::query()->where('hr_time_entry_id', $entry->id)->first();

    expect($timesheet)->not()->toBeNull()
        ->and($timesheet->user_id)->toBe($this->staff->id)
        ->and($timesheet->status)->toBe('draft')
        ->and($timesheet->work_date->toDateString())->toBe('2026-06-15')
        ->and($timesheet->break_minutes)->toBe(30)
        ->and($timesheet->total_hours)->toBe(7.5)
        ->and($timesheet->pay_rate)->not()->toBeNull()
        ->and($timesheet->pay_type)->not()->toBeNull();

    $rawTimesheet = DB::table('timesheets')->where('id', $timesheet->id)->first();
    expect(substr((string) $rawTimesheet->starts_at, 0, 19))->toBe('2026-06-14 21:00:00')
        ->and(substr((string) $rawTimesheet->ends_at, 0, 19))->toBe('2026-06-15 05:00:00');

    $timesheet->forceFill([
        'status' => 'approved',
        'submitted_at' => now(),
        'submitted_by' => $this->staff->id,
        'approved_at' => now(),
        'approved_by' => $this->hr->id,
    ])->save();

    $run = app(PayrollExportService::class)->createRun(
        1,
        now()->parse('2026-06-10')->startOfDay(),
        now()->parse('2026-06-20')->endOfDay(),
        $this->hr->id,
    );

    expect($run->items)->toHaveCount(1)
        ->and($run->items->first()->timesheet_ids)->toBe([$timesheet->id])
        ->and((float) $run->items->first()->regular_hours)->toBe(7.5)
        ->and((float) $run->items->first()->gross_pay)->toBe(225.0);
});
