<?php

use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Domain\Hr\Models\HrAttendanceSession;
use Illuminate\Support\Carbon;

it('archives published periods after completed shifts have approved timesheets', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $worker = User::factory()->create(['organization_id' => 1]);
    $weekStart = Carbon::now('Pacific/Auckland')->subWeeks(5)->startOfWeek(Carbon::MONDAY)->startOfDay();

    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => $weekStart->toDateString(),
        'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
    ]);

    $startsAt = $weekStart->copy()->setTime(9, 0)->utc();
    $endsAt = $weekStart->copy()->setTime(13, 0)->utc();

    $shift = Shift::factory()->completed()->published($period)->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'roster_period_id' => $period->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'actual_starts_at' => $startsAt,
        'actual_ends_at' => $endsAt,
    ]);

    $attendance = HrAttendanceSession::create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'shift_id' => $shift->id,
        'site_id' => $site->id,
        'clock_in_at' => $startsAt,
        'clock_out_at' => $endsAt,
        'break_minutes' => 0,
        'status' => 'closed',
        'source' => 'test',
    ]);

    Timesheet::factory()->approved()->create([
        'shift_id' => $shift->id,
        'attendance_session_id' => $attendance->id,
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'shift_site_id' => $site->id,
        'work_date' => $weekStart->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 0,
    ]);

    $this->artisan('rostering:archive-completed-periods', ['--weeks' => 2])
        ->assertSuccessful();

    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_ARCHIVED);
    expect($period->fresh()->locked_at)->not->toBeNull();
});

it('does not archive periods while payroll-relevant shifts are still unapproved', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $worker = User::factory()->create(['organization_id' => 1]);
    $weekStart = Carbon::now('Pacific/Auckland')->subWeeks(5)->startOfWeek(Carbon::MONDAY)->startOfDay();

    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => $weekStart->toDateString(),
        'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
    ]);

    Shift::factory()->completed()->published($period)->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'roster_period_id' => $period->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
    ]);

    $this->artisan('rostering:archive-completed-periods', ['--weeks' => 2])
        ->assertSuccessful();

    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_PUBLISHED);
});
