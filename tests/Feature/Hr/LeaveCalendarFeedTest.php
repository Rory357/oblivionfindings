<?php

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Services\LeaveService;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->manager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
    $this->manager->setAttribute('tenant_id', 1);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->staff->setAttribute('tenant_id', 1);
});

test('the calendar feed returns approved + pending entries, grouped people and PH shading', function () {
    $month = Carbon::parse('2026-09-01');

    HrLeaveRequest::query()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'leave_type' => 'annual', 'period' => 'full_day',
        'starts_at' => $month->copy()->day(7), 'ends_at' => $month->copy()->day(9),
        'hours_requested' => 24, 'status' => 'approved', 'submitted_at' => now(), 'escalation_level' => 1,
    ]);
    HrLeaveRequest::query()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'leave_type' => 'sick', 'period' => 'full_day',
        'starts_at' => $month->copy()->day(20), 'ends_at' => $month->copy()->day(20),
        'hours_requested' => 8, 'status' => 'pending', 'submitted_at' => now(), 'escalation_level' => 1,
    ]);
    HrPublicHoliday::query()->create([
        'tenant_id' => null, 'name' => 'Test Stat', 'date' => '2026-09-21', 'year' => 2026, 'is_national' => true,
    ]);

    $feed = app(LeaveService::class)->calendarFeed(1, '2026-09');

    expect($feed['entries'])->toHaveCount(2);
    expect(collect($feed['entries'])->pluck('status')->all())->toContain('approved', 'pending');
    expect($feed['people'])->toHaveCount(1);
    expect($feed['public_holidays'])->toHaveKey('2026-09-21');
});

test('sensitive leave reason is redacted in the calendar feed unless the viewer is HR or the subject', function () {
    $month = Carbon::parse('2026-09-01');
    $sick = HrLeaveRequest::query()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'leave_type' => 'sick', 'period' => 'full_day',
        'starts_at' => $month->copy()->day(10), 'ends_at' => $month->copy()->day(10),
        'hours_requested' => 8, 'status' => 'pending', 'submitted_at' => now(), 'escalation_level' => 1,
        'reason' => 'Hospital appointment',
    ]);

    $svc = app(LeaveService::class);
    $entryFor = fn (array $feed) => collect($feed['entries'])->firstWhere('id', $sick->id);

    // A plain approver (not the subject, no manage clearance) → reason redacted.
    $redacted = $entryFor($svc->calendarFeed(1, '2026-09', [], 9999, false));
    expect($redacted['reason'])->toBeNull();
    expect($redacted['reason_restricted'])->toBeTrue();

    // HR (manage clearance) → reason visible.
    $hr = $entryFor($svc->calendarFeed(1, '2026-09', [], 9999, true));
    expect($hr['reason'])->toBe('Hospital appointment');
    expect($hr['reason_restricted'])->toBeFalse();

    // The employee themselves → reason visible.
    $own = $entryFor($svc->calendarFeed(1, '2026-09', [], $this->staff->id, false));
    expect($own['reason'])->toBe('Hospital appointment');
    expect($own['reason_restricted'])->toBeFalse();
});

test('the hub ships the calendar prop only when the calendar tab is active', function () {
    $withTab = $this->actingAs($this->manager)->get('/hr/leave?tab=calendar&month=2026-09');
    expect($withTab->inertiaProps('calendar'))->not->toBeNull();

    $withoutTab = $this->actingAs($this->manager)->get('/hr/leave');
    expect($withoutTab->inertiaProps('calendar'))->toBeNull();
});
