<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Domain\Hr\Services\PayrollExportService;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function payrollRoleUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

test('attendance generated shift timesheet flows into payroll run with shift and site context intact', function () {
    $hr = payrollRoleUser('hr');
    $finance = payrollRoleUser('finance');
    $worker = payrollRoleUser('support_worker');
    $site = Site::factory()->create(['type' => 'house']);
    $serviceContext = ServiceContext::factory()->create([
        'name' => 'Residential Support',
        'type' => 'residential',
        'is_active' => true,
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'status' => 'active',
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'employee_number' => 'EMP-PAYROLL-'.$worker->id,
        'work_email' => $worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'hourly_rate' => '32.50',
        'is_active' => true,
        'primary_site_id' => $site->id,
        'created_by' => $hr->id,
        'updated_by' => $hr->id,
    ]);

    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $worker->id,
        'starts_at' => now(),
        'ends_at' => now()->addHours(3),
        'expected_break_minutes' => 15,
        'status' => 'scheduled',
        'created_by' => $hr->id,
    ]);

    $this->actingAs($worker)
        ->post('/attendance/clock-in', [
            'shift_id' => $shift->id,
        ])
        ->assertSessionHas('success');

    $openSession = HrAttendanceSession::query()
        ->where('user_id', $worker->id)
        ->where('shift_id', $shift->id)
        ->where('status', 'open')
        ->latest('id')
        ->firstOrFail();

    $this->travel(3)->hours();

    // The end-of-shift checklist (F-1/F-3) blocks clock-out when a
    // handover hasn't been written, so this payroll-pipeline test forces
    // the clock-out with a recorded reason. The behaviour is identical to
    // the operator dashboard's "End shift anyway" flow — see
    // `AttendanceClockOutBlockerTest` for direct coverage of the blocker.
    $this->actingAs($worker)
        ->post('/attendance/clock-out', [
            'session_id' => $openSession->id,
            'break_minutes' => 15,
            'force' => true,
            'override_reason' => 'Payroll integration test — handover covered separately.',
        ])
        ->assertSessionHas('success');

    $timesheet = Timesheet::query()
        ->where('attendance_session_id', $openSession->id)
        ->firstOrFail();

    expect((int) $timesheet->shift_id)->toBe($shift->id)
        ->and((int) $timesheet->client_id)->toBe($client->id)
        ->and((int) $timesheet->shift_site_id)->toBe($site->id)
        ->and($timesheet->reconciliation_status)->toBe('clear');

    $this->actingAs($worker)
        ->post(route('timesheets.submit', $timesheet))
        ->assertSessionHas('success');

    $timesheet->refresh();
    expect($timesheet->status)->toBe('submitted');

    $this->actingAs($finance)
        ->post(route('timesheets.approve', $timesheet))
        ->assertSessionHas('success');

    $timesheet->refresh();
    expect($timesheet->status)->toBe('approved');

    $run = app(PayrollExportService::class)->createRun(
        tenantId: 1,
        periodStart: now()->subWeek()->startOfDay(),
        periodEnd: now()->endOfDay(),
        createdBy: $finance->id,
    );

    $item = $run->items()->where('user_id', $worker->id)->first();

    expect($item)->not->toBeNull()
        ->and($item->timesheet_ids)->toContain($timesheet->id)
        ->and((float) $item->gross_pay)->toBeGreaterThan(0);
});

test('returned shift timesheet keeps special pay flags intact when resubmitted into payroll', function () {
    $hr = payrollRoleUser('hr');
    $finance = payrollRoleUser('finance');
    $worker = payrollRoleUser('support_worker');
    $site = Site::factory()->create(['type' => 'house']);
    $serviceContext = ServiceContext::factory()->create([
        'name' => 'Residential Support',
        'type' => 'residential',
        'is_active' => true,
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'status' => 'active',
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'employee_number' => 'EMP-SPECIAL-'.$worker->id,
        'work_email' => $worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'hourly_rate' => '32.50',
        'is_active' => true,
        'primary_site_id' => $site->id,
        'created_by' => $hr->id,
        'updated_by' => $hr->id,
    ]);

    HrPayRateRule::query()->create([
        'tenant_id' => 1,
        'name' => 'Special Shift Premium',
        'is_active' => true,
        'priority' => 10,
        'position_role' => 'support_worker',
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'applies_on_public_holiday' => true,
        'applies_on_sleepover' => true,
        'applies_on_call' => true,
        'regular_multiplier' => 1.00,
        'overtime_multiplier' => 2.00,
        'public_holiday_multiplier' => 2.50,
        'sleepover_flat_rate' => 120.00,
        'on_call_hourly_rate' => 8.00,
        'created_by' => $hr->id,
        'updated_by' => $hr->id,
    ]);

    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $worker->id,
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(18, 0),
        'expected_break_minutes' => 0,
        'is_sleepover' => true,
        'is_on_call' => true,
        'status' => 'scheduled',
        'created_by' => $hr->id,
    ]);

    $timesheet = Timesheet::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'shift_site_id' => $site->id,
        'shift_service_context_id' => $serviceContext->id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(18, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'sleepover' => true,
        'on_call' => true,
        'status' => 'draft',
        'created_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->post(route('timesheets.submit', $timesheet))
        ->assertSessionHas('success');

    $this->actingAs($finance)
        ->post(route('timesheets.return', $timesheet), [
            'returned_notes' => 'Confirm public holiday details before export.',
        ])
        ->assertSessionHas('success');

    $this->actingAs($worker)
        ->put(route('timesheets.update', $timesheet), [
            'client_id' => $client->id,
            'work_date' => now()->subDay()->toDateString(),
            'starts_at' => now()->subDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->subDay()->setTime(18, 0)->format('Y-m-d H:i:s'),
            'break_minutes' => 0,
            'public_holiday' => true,
            'notes' => 'Confirmed public holiday sleepover coverage.',
        ])
        ->assertSessionHas('success');

    $this->actingAs($worker)
        ->post(route('timesheets.submit', $timesheet))
        ->assertSessionHas('success');

    $this->actingAs($finance)
        ->post(route('timesheets.approve', $timesheet))
        ->assertSessionHas('success');

    $timesheet->refresh();
    expect($timesheet->status)->toBe('approved')
        ->and($timesheet->public_holiday)->toBeTrue()
        ->and($timesheet->sleepover)->toBeTrue()
        ->and($timesheet->on_call)->toBeTrue();

    $run = app(PayrollExportService::class)->createRun(
        tenantId: 1,
        periodStart: now()->subWeek()->startOfDay(),
        periodEnd: now()->endOfDay(),
        createdBy: $finance->id,
    );

    $item = $run->items()->where('user_id', $worker->id)->firstOrFail();

    expect($item->timesheet_ids)->toContain($timesheet->id)
        ->and((float) $item->regular_hours)->toBe(8.0)
        ->and((float) $item->overtime_hours)->toBe(1.0)
        ->and((float) $item->public_holiday_hours)->toBe(9.0)
        ->and((float) $item->on_call_hours)->toBe(9.0)
        ->and((int) $item->sleepover_count)->toBe(1)
        ->and((float) $item->public_holiday_multiplier)->toBe(2.5)
        ->and((float) $item->sleepover_rate)->toBe(120.0)
        ->and((float) $item->on_call_rate)->toBe(8.0)
        ->and(round((float) $item->gross_pay, 2))->toBe(955.75)
        ->and((float) data_get($item->rate_breakdown, 'holiday_loading'))->toBe(438.75)
        ->and((float) data_get($item->rate_breakdown, 'sleepover_pay'))->toBe(120.0)
        ->and((float) data_get($item->rate_breakdown, 'on_call_pay'))->toBe(72.0)
        ->and((float) data_get($item->rate_breakdown, 'line_items.0.public_holiday_hours'))->toBe(9.0)
        ->and((float) data_get($item->rate_breakdown, 'line_items.0.on_call_hours'))->toBe(9.0)
        ->and(data_get($item->rate_breakdown, 'line_items.0.sleepover_count'))->toBe(1);
});
