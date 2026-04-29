<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->worker->roles()->syncWithoutDetaching([$supportRole->id]);
        $this->otherWorker->roles()->syncWithoutDetaching([$supportRole->id]);
    }
});

test('cross-user clock out attempts return 403 and write an audit row', function () {
    $client = Client::factory()->create();
    $serviceContext = ServiceContext::factory()->create();
    $shift = Shift::query()->create([
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->worker->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(7),
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHour(),
        'started_by' => $this->worker->id,
        'created_by' => $this->worker->id,
    ]);

    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'clock_in_at' => now()->subHour(),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);

    $this->actingAs($this->otherWorker)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
        ])
        ->assertForbidden();

    expect($session->fresh()->status)->toBe('open');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'attendance.clockOut.unauthorized',
        'auditable_id' => $session->id,
        'user_id' => $this->otherWorker->id,
    ]);
});

test('workers who can clock can open their attendance session list', function () {
    $this->actingAs($this->worker)
        ->get('/attendance')
        ->assertOk();
});
