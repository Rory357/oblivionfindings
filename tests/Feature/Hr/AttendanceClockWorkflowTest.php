<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }
});

test('staff can clock in and clock out to create draft timesheet from attendance session', function () {
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create();

    $shift = Shift::query()->create([
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
});

test('staff cannot start a second open attendance session', function () {
    HrAttendanceSession::query()->create([
        'tenant_id' => null,
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

test('clock out reuses an existing draft timesheet for the same shift and staff member', function () {
    $serviceContext = ServiceContext::factory()->create();
    $client = Client::factory()->create();

    $shift = Shift::query()->create([
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
        ->and((int) $existingTimesheet->break_minutes)->toBe(20);
});

// ──────────────────────────────────────────────
// Break duration validation
// ──────────────────────────────────────────────

test('clock out rejects break_minutes exceeding session duration', function () {
    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
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
        'tenant_id' => null,
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
        'tenant_id' => null,
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
        'tenant_id' => null,
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
