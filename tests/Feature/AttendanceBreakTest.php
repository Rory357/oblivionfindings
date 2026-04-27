<?php

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Role;
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

test('staff can start and end a break on an open attendance session', function () {
    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subHour(),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/break/start', ['session_id' => $session->id])
        ->assertSessionHas('success');

    $session->refresh();
    expect($session->break_started_at)->not->toBeNull()
        ->and((int) $session->break_count)->toBe(1);

    $this->travel(15)->minutes();

    $this->actingAs($this->staff)
        ->post('/attendance/break/end', ['session_id' => $session->id])
        ->assertSessionHas('success');

    $session->refresh();
    expect($session->break_started_at)->toBeNull()
        ->and((int) $session->break_minutes)->toBeGreaterThanOrEqual(15);

    expect(HrAttendanceBreakEvent::query()->where('session_id', $session->id)->whereNotNull('ended_at')->exists())->toBeTrue();
});

test('staff cannot start a second break while one is already open', function () {
    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->staff->id,
        'clock_in_at' => now()->subHour(),
        'break_started_at' => now()->subMinutes(5),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/break/start', ['session_id' => $session->id])
        ->assertSessionHasErrors(['break']);
});
