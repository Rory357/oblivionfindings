<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->staff->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
});

test('staff can clock in and clock out to create draft timesheet from attendance session', function () {
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    $shift = Shift::query()->create([
        'site_id' => $this->site->id,
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->staff->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(7),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-in', [
            'shift_id' => $shift->id,
        ])
        ->assertSessionHas('success');

    $openSession = HrAttendanceSession::query()
        ->where('user_id', $this->staff->id)
        ->where('status', 'open')
        ->first();

    expect($openSession)->not->toBeNull();
    expect($openSession?->shift_id)->toBe($shift->id);
    expect((int) $openSession?->site_id)->toBe($this->site->id);

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $shift->id,
            'meds_completed' => true,
            'shift_rating' => 'calm',
            'handover_notes' => 'Shift was calm.',
            'follow_up_needed' => false,
        ])
        ->assertSessionHas('success');

    // Advance time so the session has meaningful duration for break validation
    $this->travel(2)->hours();

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $openSession->id,
            'break_minutes' => 15,
        ])
        ->assertSessionHas('success');

    $closedSession = $openSession->fresh();
    expect($closedSession?->status)->toBe('closed');
    expect($closedSession?->clock_out_at)->not->toBeNull();
    expect((int) $closedSession?->break_minutes)->toBe(15);

    $timesheet = Timesheet::query()->where('attendance_session_id', $closedSession->id)->first();
    expect($timesheet)->not->toBeNull();
    expect($timesheet?->status)->toBe('draft');
    expect((int) $timesheet?->shift_id)->toBe($shift->id);
    expect((int) $timesheet?->client_id)->toBe($client->id);
    expect((int) $timesheet?->shift_site_id)->toBe($this->site->id);
});

test('staff cannot start a second open attendance session', function () {
    HrAttendanceSession::query()->create([
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-in')
        ->assertSessionHasErrors(['clock_in']);
});

test('clock-in endpoints share the single open attendance session guard', function (string $initialEndpoint) {
    $hrTimePermission = Permission::query()->where('key', 'hr.time.viewAny')->firstOrFail();
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $hrTimePermission->id => ['allowed' => true],
    ]);

    $endpoints = [
        'attendance' => '/attendance/clock-in',
        'hr_self_service' => '/hr/my/time/clock-in',
        'hr_time' => '/hr/time/clock-in',
    ];

    $this->actingAs($this->staff)
        ->post($endpoints[$initialEndpoint])
        ->assertSessionHas('success');

    foreach ($endpoints as $name => $endpoint) {
        if ($name === $initialEndpoint) {
            continue;
        }

        $response = $this->actingAs($this->staff)->post($endpoint);

        if ($name === 'attendance') {
            $response->assertSessionHasErrors(['clock_in']);
        } else {
            $response->assertSessionHas('error');
        }
    }

    expect(HrAttendanceSession::query()
        ->where('user_id', $this->staff->id)
        ->open()
        ->count())->toBe(1);
})->with([
    'attendance',
    'hr_self_service',
    'hr_time',
]);

test('S1 seam: the HrTimeEntry and HrAttendanceSession read paths agree across both clock surfaces', function () {
    // /hr/my/time reads the live clock via HrTimeEntry::active(); /my-day's
    // /attendance reads it via HrAttendanceSession::open(). Both are written
    // atomically through the one AttendanceService projection transaction,
    // so a clock made on either surface must be visible to the other's read path,
    // and a clock-out on either must clear both (never a phantom "on shift").
    $hrTimePermission = Permission::query()->where('key', 'hr.time.viewAny')->firstOrFail();
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $hrTimePermission->id => ['allowed' => true],
    ]);

    $openSessions = fn () => HrAttendanceSession::query()
        ->where('user_id', $this->staff->id)->open()->count();
    $activeEntries = fn () => HrTimeEntry::query()
        ->forUser($this->staff->id)->active()->count();

    expect($openSessions())->toBe(0);
    expect($activeEntries())->toBe(0);

    // Clock IN on /my-day (AttendanceController) → BOTH read paths must see it.
    $this->actingAs($this->staff)->post('/attendance/clock-in')->assertSessionHas('success');
    expect($openSessions())->toBe(1);  // AttendanceController's read path
    expect($activeEntries())->toBe(1); // MyHrController's read path — the /my-day clock surfaces in /hr/my/time

    // A session must have positive duration — advance the clock before clocking out.
    $this->travel(2)->hours();

    // Clock OUT on the OTHER surface (/hr/my/time) → both must clear.
    $this->actingAs($this->staff)->post('/hr/my/time/clock-out')->assertSessionHas('success');
    expect($openSessions())->toBe(0);
    expect($activeEntries())->toBe(0);

    // Reverse: clock IN on /hr/my/time → BOTH read paths must see it.
    $this->actingAs($this->staff)->post('/hr/my/time/clock-in')->assertSessionHas('success');
    expect($openSessions())->toBe(1);  // the /hr/my/time clock surfaces in /my-day
    expect($activeEntries())->toBe(1);

    $this->travel(2)->hours();

    // Clock OUT on /hr/my/time → both clear.
    $this->actingAs($this->staff)->post('/hr/my/time/clock-out')->assertSessionHas('success');
    expect($openSessions())->toBe(0);
    expect($activeEntries())->toBe(0);
});

test('clock out reuses an existing draft timesheet for the same shift and staff member', function () {
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    $shift = Shift::query()->create([
        'site_id' => $this->site->id,
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->staff->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(7),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);

    $existingTimesheet = Timesheet::factory()->create([
        'shift_id' => $shift->id,
        'client_id' => $client->id,
        'user_id' => $this->staff->id,
        'shift_site_id' => $this->site->id,
        'shift_service_context_id' => $serviceContext->id,
        'shift_site_name_snapshot' => $this->site->name,
        'client_name_snapshot' => $client->full_name,
        'staff_name_snapshot' => $this->staff->name,
        'break_minutes' => 45,
        'status' => 'draft',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-in', [
            'shift_id' => $shift->id,
        ])
        ->assertSessionHas('success');

    $openSession = HrAttendanceSession::query()
        ->where('user_id', $this->staff->id)
        ->where('status', 'open')
        ->latest('id')
        ->first();

    expect((int) $openSession?->site_id)->toBe($this->site->id);

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $shift->id,
            'meds_completed' => true,
            'shift_rating' => 'calm',
            'handover_notes' => 'Shift was calm.',
            'follow_up_needed' => false,
        ])
        ->assertSessionHas('success');

    // Advance time so the session has meaningful duration for break validation
    $this->travel(2)->hours();

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $openSession->id,
            'break_minutes' => 20,
        ])
        ->assertSessionHas('success');

    expect(
        Timesheet::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $this->staff->id)
            ->count()
    )->toBe(1);

    $existingTimesheet->refresh();
    expect((int) $existingTimesheet->attendance_session_id)->toBe($openSession->id)
        ->and((int) $existingTimesheet->break_minutes)->toBe(45)
        ->and((int) $existingTimesheet->shift_site_id)->toBe($this->site->id)
        ->and((int) $openSession->fresh()->break_minutes)->toBe(20);
});

test('clock in without an explicit shift is blocked when multiple eligible shifts match', function () {
    $serviceContext = ServiceContext::factory()->create();

    Shift::query()->create([
        'client_id' => Client::factory()->create()->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->staff->id,
        'starts_at' => now()->subMinutes(30),
        'ends_at' => now()->addHours(3),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);

    Shift::query()->create([
        'client_id' => Client::factory()->create()->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->staff->id,
        'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addHours(4),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-in')
        ->assertSessionHasErrors(['clock_in']);

    expect(HrAttendanceSession::query()
        ->where('user_id', $this->staff->id)
        ->open()
        ->exists())->toBeFalse();
});

// ──────────────────────────────────────────────
// Break duration validation
// ──────────────────────────────────────────────

test('clock out rejects break_minutes exceeding session duration', function () {
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subMinutes(60),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    // Session is 60 minutes, break of 90 is impossible
    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 90,
        ])
        ->assertSessionHasErrors(['clock_out']);

    $session->refresh();
    expect($session->status)->toBe('open');
});

test('clock out rejects break_minutes equal to session duration', function () {
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subMinutes(60),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    // 60 min break on a 60 min session = zero payable time
    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 60,
        ])
        ->assertSessionHasErrors(['clock_out']);

    $session->refresh();
    expect($session->status)->toBe('open');
});

test('clock out accepts valid break shorter than session duration', function () {
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subMinutes(120),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    // 30 min break on a 120 min session is fine
    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 30,
        ])
        ->assertSessionHas('success');

    $session->refresh();
    expect($session->status)->toBe('closed');
    expect((int) $session->break_minutes)->toBe(30);
});

test('clock out with zero break on short session succeeds', function () {
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subMinutes(5),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
        ])
        ->assertSessionHas('success');

    $session->refresh();
    expect($session->status)->toBe('closed');
    expect((int) $session->break_minutes)->toBe(0);
});

test('clock out rejects break_minutes above the shared 240 cap', function () {
    // F3 — break cap unified to 240 everywhere. A 5-hour break is rejected by
    // validation even on this 6-hour session (so the cap, not the
    // break-vs-duration rule, is what blocks it).
    $session = HrAttendanceSession::query()->create([
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subMinutes(360),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 300,
        ])
        ->assertSessionHasErrors(['break_minutes']);

    $session->refresh();
    expect($session->status)->toBe('open');
});

// ──────────────────────────────────────────────
// Clock-surface consistency safeguard
//
// All three clock entry points funnel through AttendanceService, so a full
// clock-in → clock-out cycle on any of them must produce exactly one
// HrAttendanceSession and exactly one canonical draft Timesheet (linked to
// that session). Per backend handoff §5 every surface — including the canonical
// /attendance surface — now also maintains exactly one HrTimeEntry via the
// shared TimeTrackingService::syncEntryFromSession, so the HR Time-entries tab
// and HrTimeEntry-based KPIs include /my-day clockers. Locks in the unified
// "one session · one timesheet · one time-entry" contract across all paths.
// ──────────────────────────────────────────────

test('every clock surface funnels into one session and one canonical timesheet', function (array $surface) {
    // /hr/my/time needs only auth; /hr/time + /attendance ride on the
    // support_worker role's timesheet permissions. Grant hr.time.viewAny to
    // mirror the shared-guard test's proven setup.
    $hrTimePermission = Permission::query()->where('key', 'hr.time.viewAny')->firstOrFail();
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $hrTimePermission->id => ['allowed' => true],
    ]);

    $this->actingAs($this->staff)
        ->post($surface['clock_in'])
        ->assertSessionHas('success');

    // Give the session a non-zero duration so clock-out is valid.
    $this->travel(2)->hours();

    $this->actingAs($this->staff)
        ->post($surface['clock_out'], ['break_minutes' => 0])
        ->assertSessionHas('success');

    $sessions = HrAttendanceSession::query()
        ->where('user_id', $this->staff->id)
        ->get();
    expect($sessions)->toHaveCount(1);

    $session = $sessions->first();
    expect($session->status)->toBe('closed')
        ->and((int) $session->site_id)->toBe($this->site->id);

    $timesheets = Timesheet::query()
        ->where('user_id', $this->staff->id)
        ->where('status', 'draft')
        ->get();
    expect($timesheets)->toHaveCount(1);
    expect((int) $timesheets->first()->attendance_session_id)->toBe((int) $session->id)
        ->and((int) $timesheets->first()->site_id)->toBe($this->site->id);

    $timeEntries = HrTimeEntry::query()->where('user_id', $this->staff->id)->get();
    expect($timeEntries)->toHaveCount($surface['expected_time_entries'])
        ->and((int) $timeEntries->sole()->attendance_session_id)->toBe((int) $session->id)
        ->and((int) $timeEntries->sole()->site_id)->toBe($this->site->id);
})->with([
    'canonical attendance' => [[
        'clock_in' => '/attendance/clock-in',
        'clock_out' => '/attendance/clock-out',
        'expected_time_entries' => 1,
    ]],
    'hr self-service' => [[
        'clock_in' => '/hr/my/time/clock-in',
        'clock_out' => '/hr/my/time/clock-out',
        'expected_time_entries' => 1,
    ]],
    'hr time tracking' => [[
        'clock_in' => '/hr/time/clock-in',
        'clock_out' => '/hr/time/clock-out',
        'expected_time_entries' => 1,
    ]],
]);
