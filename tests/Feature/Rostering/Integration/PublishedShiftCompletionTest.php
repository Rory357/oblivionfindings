<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;

it('keeps auto-drafting timesheets when a published shift is completed', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $role = Role::query()->create([
        'name' => 'published-completion-'.str()->uuid(),
        'label' => 'Published shift completion test',
        'level' => 10,
        'type' => 'custom',
    ]);
    foreach (['shifts.update', 'shifts.manageAny'] as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'shifts', 'module' => 'Operations'],
        );
        $role->permissions()->attach($permission);
    }
    $worker = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
    $actor = User::factory()->create(['organization_id' => 1, 'role' => $role->name, 'approved_at' => now()]);
    $actor->roles()->attach($role);
    ensureCanonicalHrStaffProfile($worker, $site);
    ensureCanonicalHrStaffProfile($actor, $site);
    expect($actor->canDo('shifts.update'))->toBeTrue();
    expect($actor->canDo('shifts.manageAny'))->toBeTrue();
    expect($actor->canDo('reports.viewAny'))->toBeFalse();
    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => '2026-05-04',
        'week_end' => '2026-05-11',
    ]);

    $startsAt = Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc();
    $endsAt = Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc();

    $shift = Shift::factory()->published($period)->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'roster_period_id' => $period->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'actual_starts_at' => $startsAt,
        'status' => 'in_progress',
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

    $lifecycle = app(ShiftLifecycleService::class);

    $completed = $lifecycle->complete(
        $shift->fresh(),
        $actor,
        new CompleteShiftData(
            actualStartsAt: $startsAt,
            actualEndsAt: $endsAt,
            createSummaryNote: false,
        ),
    );

    $result = $lifecycle->lastDraftTimesheetResult();
    $timesheet = Timesheet::query()->where('shift_id', $shift->id)->first();

    expect($completed->status)->toBe('completed');
    expect($completed->published_at)->not->toBeNull();
    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH);
    expect($result['success'])->toBeTrue();
    expect($timesheet)->not->toBeNull();
    expect($timesheet->status)->toBe('draft');
    expect($timesheet->user_id)->toBe($worker->id);
    expect($timesheet->client_id)->toBe($client->id);
    expect($timesheet->shift_site_id)->toBe($site->id);
    expect(Timesheet::query()->where('shift_id', $shift->id)->count())->toBe(1);
    expect($timesheet->attendance_session_id)->toBe($attendance->id);
    expect($timesheet->starts_at->equalTo($startsAt))->toBeTrue();
    expect($timesheet->ends_at->equalTo($endsAt))->toBeTrue();
    expect($timesheet->reconciliation_status)->toBe('clear');
});
