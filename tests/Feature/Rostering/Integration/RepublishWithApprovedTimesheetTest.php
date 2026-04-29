<?php

use App\Domain\Rostering\RosterPublishingService;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;

it('republishes without changing approved timesheet snapshots', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $actor = User::factory()->create(['organization_id' => 1]);
    $worker = User::factory()->create(['organization_id' => 1]);
    $weekStart = Carbon::parse('2026-05-04', 'Pacific/Auckland')->startOfDay();

    $period = RosterPeriod::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => $weekStart->toDateString(),
        'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
    ]);

    $startsAt = $weekStart->copy()->setTime(9, 0)->utc();
    $endsAt = $weekStart->copy()->setTime(13, 0)->utc();

    $shift = Shift::factory()->completed()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'actual_starts_at' => $startsAt,
        'actual_ends_at' => $endsAt,
    ]);

    $published = app(RosterPublishingService::class)->publish($period, $actor);

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

    $timesheet = Timesheet::factory()->approved()->create([
        'shift_id' => $shift->id,
        'attendance_session_id' => $attendance->id,
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'shift_site_id' => $site->id,
        'work_date' => $weekStart->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 0,
        'shift_type_snapshot' => $shift->shift_type ?: 'standard',
    ]);

    $shift->update(['notes' => 'Updated support note for the roster diff.']);

    $republished = app(RosterPublishingService::class)->republish($published->fresh(), $actor);

    expect($republished->version)->toBe(2);
    expect($timesheet->fresh()->starts_at->equalTo($weekStart->copy()->setTime(9, 0)->utc()))->toBeTrue();
    expect($timesheet->fresh()->ends_at->equalTo($weekStart->copy()->setTime(13, 0)->utc()))->toBeTrue();
});
