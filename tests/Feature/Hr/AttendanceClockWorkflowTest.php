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

