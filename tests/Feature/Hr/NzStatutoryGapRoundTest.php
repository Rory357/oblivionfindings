<?php

use App\Domain\Hr\Jobs\ProcessLeaveBalanceAccrualJob;
use App\Domain\Hr\Jobs\SendExpiryRemindersJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Notifications\VisaExpiryNotification;
use App\Domain\Hr\Services\AlternativeHolidayService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\PublicHolidayCalendar;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\HrPayEquityBandsSeeder;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    if ($hrRole = Role::query()->where('name', 'hr')->first()) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    if ($supportRole = Role::query()->where('name', 'support_worker')->first()) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->site = Site::factory()->create(['name' => 'NZ Statutory Allowed Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);

    $this->profile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-NZGAP-001',
        'work_email' => "nz-gap-worker-{$this->staff->id}@example.test",
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => '30.00',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
        'primary_site_id' => $this->site->id,
    ]);
});

test('family violence leave is a valid statutory type with accrual support', function () {
    expect(LeaveService::LEAVE_TYPES)->toContain('family_violence')
        ->and(LeaveService::LEAVE_TYPES)->toContain('alternative')
        ->and(config('hr.leave.accrual_types'))->toContain('family_violence');

    $request = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'family_violence',
        'starts_at' => '2026-07-06',
        'ends_at' => '2026-07-07',
        'reason' => 'Statutory leave',
    ]);

    expect($request->leave_type)->toBe('family_violence')
        ->and($request->status)->toBe('pending');

    (new ProcessLeaveBalanceAccrualJob)->handle(app(HrCurrentStaffService::class));

    $ledger = HrLeaveBalanceLedger::query()
        ->where('user_id', $this->staff->id)
        ->where('leave_type', 'family_violence')
        ->where('entry_type', 'accrual')
        ->first();

    expect($ledger)->not()->toBeNull()
        ->and((float) $ledger->hours_delta)->toBe(round(80 / 12, 2));
});

test('public holiday calendar matches national days and region-scoped anniversaries', function () {
    HrPublicHoliday::query()->create([
        'name' => 'Waitangi Day', 'date' => '2026-02-06', 'is_national' => true, 'year' => 2026,
    ]);
    HrPublicHoliday::query()->create([
        'name' => 'Auckland Anniversary Day', 'date' => '2026-01-26', 'region' => 'auckland', 'is_national' => false, 'year' => 2026,
    ]);

    $calendar = app(PublicHolidayCalendar::class);

    expect($calendar->isPublicHoliday('2026-02-06'))->toBeTrue()
        ->and($calendar->isPublicHoliday('2026-02-06', 'wellington'))->toBeTrue()
        ->and($calendar->isPublicHoliday('2026-01-26', 'Auckland'))->toBeTrue()
        ->and($calendar->isPublicHoliday('2026-01-26', 'wellington'))->toBeFalse()
        ->and($calendar->isPublicHoliday('2026-01-26'))->toBeFalse()
        ->and($calendar->isPublicHoliday('2026-02-05'))->toBeFalse();
});

test('working a public holiday flags the timesheet and approval accrues an alternative holiday once', function () {
    HrPublicHoliday::query()->create([
        'name' => 'Waitangi Day', 'date' => '2026-02-06', 'is_national' => true, 'year' => 2026,
    ]);

    $entry = app(TimeTrackingService::class)->createManualEntry($this->hr, [
        'user_id' => $this->staff->id,
        'client_id' => Client::factory()->create()->id,
        'clock_in' => '2026-02-06T09:00',
        'clock_out' => '2026-02-06T17:00',
        'break_minutes' => 30,
    ]);

    $timesheet = Timesheet::query()->where('hr_time_entry_id', $entry->id)->firstOrFail();

    expect($timesheet->public_holiday)->toBeTrue()
        ->and($timesheet->work_date->toDateString())->toBe('2026-02-06');

    $approvals = app(TimesheetApprovalService::class);
    $approvals->submit($timesheet, $this->staff);
    $approvals->approve($timesheet->fresh(), $this->hr);

    $balance = HrLeaveBalance::query()
        ->where('user_id', $this->staff->id)
        ->where('leave_type', 'alternative')
        ->where('year', 2026)
        ->first();

    expect($balance)->not()->toBeNull()
        ->and((float) $balance->balance_hours)->toBe(8.0)
        ->and((float) $balance->accrued_hours)->toBe(8.0);

    // Re-running the accrual (e.g. re-approval cycles) never double-credits.
    app(AlternativeHolidayService::class)
        ->accrueForTimesheet($timesheet->fresh());

    expect(HrLeaveBalanceLedger::query()
        ->where('source_type', 'timesheet')
        ->where('source_id', $timesheet->id)
        ->where('leave_type', 'alternative')
        ->count())->toBe(1)
        ->and((float) $balance->fresh()->balance_hours)->toBe(8.0);
});

test('casual staff do not accrue alternative holidays', function () {
    HrPublicHoliday::query()->create([
        'name' => 'Waitangi Day', 'date' => '2026-02-06', 'is_national' => true, 'year' => 2026,
    ]);

    $this->profile->update(['employment_type' => 'casual']);

    $entry = app(TimeTrackingService::class)->createManualEntry($this->hr, [
        'user_id' => $this->staff->id,
        'client_id' => Client::factory()->create()->id,
        'clock_in' => '2026-02-06T09:00',
        'clock_out' => '2026-02-06T13:00',
    ]);

    $timesheet = Timesheet::query()->where('hr_time_entry_id', $entry->id)->firstOrFail();
    expect($timesheet->public_holiday)->toBeTrue();

    $approvals = app(TimesheetApprovalService::class);
    $approvals->submit($timesheet, $this->staff);
    $approvals->approve($timesheet->fresh(), $this->hr);

    expect(HrLeaveBalance::query()
        ->where('user_id', $this->staff->id)
        ->where('leave_type', 'alternative')
        ->exists())->toBeFalse();
});

test('visa expiry reminders notify the worker and their manager without duplicates', function () {
    $this->profile->update([
        'work_rights_status' => 'work_visa',
        'visa_type' => 'Accredited Employer Work Visa',
        'visa_expires_at' => now()->addDays(30)->toDateString(),
        'manager_user_id' => $this->hr->id,
    ]);

    (new SendExpiryRemindersJob)->handle();

    $staffNotifications = $this->staff->notifications()
        ->where('type', VisaExpiryNotification::class)->get();
    $managerNotifications = $this->hr->notifications()
        ->where('type', VisaExpiryNotification::class)->get();

    expect($staffNotifications)->toHaveCount(1)
        ->and($managerNotifications)->toHaveCount(1)
        ->and($staffNotifications->first()->data['reminder_days'])->toBe(30);

    (new SendExpiryRemindersJob)->handle();

    expect($this->staff->notifications()->where('type', VisaExpiryNotification::class)->count())->toBe(1);
});

test('hr managers can record work rights on the employee profile', function () {
    $response = $this->actingAs($this->hr)->put("/hr/people/{$this->profile->id}", [
        'position_title' => 'Support Worker',
        'employment_type' => 'full_time',
        'work_rights_status' => 'work_visa',
        'visa_type' => 'Accredited Employer Work Visa',
        'visa_expires_at' => '2027-03-31',
    ]);

    $response->assertSessionHasNoErrors();

    $fresh = $this->profile->fresh();
    expect($fresh->work_rights_status)->toBe('work_visa')
        ->and($fresh->visa_type)->toBe('Accredited Employer Work Visa')
        ->and($fresh->visa_expires_at->toDateString())->toBe('2027-03-31');
});

test('pay equity bands seeder creates the settlement structure and is in the production chain', function () {
    $this->seed(HrPayEquityBandsSeeder::class);

    $bands = HrSalaryBand::query()
        ->where('band_name', 'like', 'Pay Equity%')
        ->get();

    expect($bands)->toHaveCount(4)
        ->and($bands->pluck('currency')->unique()->all())->toBe(['NZD']);

    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($databaseSeeder)->toContain('HrPayEquityBandsSeeder::class');
});
