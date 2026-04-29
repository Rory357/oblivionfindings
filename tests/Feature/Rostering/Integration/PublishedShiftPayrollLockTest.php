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
use Illuminate\Validation\ValidationException;

it('keeps payroll critical shift fields locked after a published shift has an approved timesheet', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $worker = User::factory()->create(['organization_id' => 1]);
    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => '2026-05-04',
        'week_end' => '2026-05-11',
    ]);

    $startsAt = Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc();
    $endsAt = Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc();

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
        'work_date' => '2026-05-04',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 0,
    ]);

    expect(fn () => $shift->update([
        'starts_at' => Carbon::parse('2026-05-04 10:00:00', 'Pacific/Auckland')->utc(),
    ]))->toThrow(ValidationException::class);

    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_PUBLISHED);
    expect($shift->fresh()->publish_dirty_at)->toBeNull();
});

it('prevents unpublishing a roster period once a linked timesheet is approved', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $worker = User::factory()->create(['organization_id' => 1]);
    $actor = User::factory()->create(['organization_id' => 1]);
    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => '2026-05-04',
        'week_end' => '2026-05-11',
    ]);

    $startsAt = Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc();
    $endsAt = Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc();

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
        'work_date' => '2026-05-04',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 0,
    ]);

    expect(fn () => app(RosterPublishingService::class)->unpublish($period, $actor))
        ->toThrow(ValidationException::class);

    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_PUBLISHED);
    expect($shift->fresh()->published_at)->not->toBeNull();
});
