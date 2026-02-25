<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP78001',
        'work_email' => "worker-{$this->worker->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('hr can create default payroll export profile and export mapped csv', function () {
    $this->actingAs($this->hr)
        ->post('/hr/payroll/export-profiles', [
            'name' => 'MYOB Custom',
            'provider_key' => 'myob',
            'description' => 'MYOB payroll import schema',
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => '\n',
            'include_headers' => true,
            'is_default' => true,
            'mappings' => [
                ['header' => 'EmpID', 'source' => 'employee_number'],
                ['header' => 'Worker', 'source' => 'name'],
                ['header' => 'Hours', 'source' => 'regular_hours'],
                ['header' => 'Company', 'source' => 'static', 'value' => 'Oblivion Findings'],
            ],
        ])
        ->assertSessionHas('success');

    $profile = HrPayrollExportProfile::query()->latest('id')->first();
    expect($profile)->not->toBeNull();
    expect($profile->is_default)->toBeTrue();
    expect($profile->tenant_id)->toBe(1);

    $run = HrPayrollRun::query()->create([
        'tenant_id' => 1,
        'period_start' => now()->subDays(14)->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'status' => 'locked',
        'locked_at' => now()->subHours(2),
        'locked_by' => $this->hr->id,
        'total_hours' => 8,
        'total_gross' => 240,
        'total_staff' => 1,
        'created_by' => $this->hr->id,
    ]);

    HrPayrollRunItem::query()->create([
        'payroll_run_id' => $run->id,
        'user_id' => $this->worker->id,
        'timesheet_ids' => [101],
        'base_hourly_rate' => 30,
        'overtime_multiplier' => 1.5,
        'public_holiday_multiplier' => 1.5,
        'sleepover_rate' => 0,
        'on_call_rate' => 0,
        'regular_hours' => 8,
        'overtime_hours' => 0,
        'sleepover_count' => 0,
        'on_call_hours' => 0,
        'mileage_km' => 0,
        'public_holiday_hours' => 0,
        'gross_pay' => 240,
        'allowances' => [],
        'rate_breakdown' => [],
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/payroll/runs/{$run->id}/export", [
            'profile_id' => $profile->id,
        ])
        ->assertDownload();

    $run->refresh();
    expect($run->status)->toBe('exported');
    expect($run->export_profile_id)->toBe($profile->id);
    expect($run->export_path)->not->toBeNull();
    expect(Storage::disk('private')->exists((string) $run->export_path))->toBeTrue();

    $csv = Storage::disk('private')->get((string) $run->export_path);
    expect($csv)->toContain('"EmpID","Worker","Hours","Company"');
    expect($csv)->toContain('"EMP78001"');
    expect($csv)->toContain('"Oblivion Findings"');
});

test('setting a new default payroll export profile unsets previous defaults', function () {
    $first = HrPayrollExportProfile::query()->create([
        'tenant_id' => 1,
        'name' => 'Default A',
        'provider_key' => 'custom',
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '\n',
        'include_headers' => true,
        'is_default' => true,
        'mappings' => [
            ['header' => 'Employee', 'source' => 'employee_number'],
        ],
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $second = HrPayrollExportProfile::query()->create([
        'tenant_id' => 1,
        'name' => 'Default B',
        'provider_key' => 'custom',
        'delimiter' => ',',
        'enclosure' => '"',
        'line_ending' => '\n',
        'include_headers' => true,
        'is_default' => false,
        'mappings' => [
            ['header' => 'Employee', 'source' => 'employee_number'],
        ],
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/payroll/export-profiles/{$second->id}/set-default")
        ->assertSessionHas('success');

    $first->refresh();
    $second->refresh();

    expect($first->is_default)->toBeFalse();
    expect($second->is_default)->toBeTrue();
});

