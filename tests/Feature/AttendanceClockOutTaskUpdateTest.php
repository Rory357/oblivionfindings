<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->worker->roles()->syncWithoutDetaching([$supportRole->id]);
    }
});

function attendanceTaskUpdateOpenSessionFor(User $worker): array
{
    $client = Client::factory()->create();
    $serviceContext = ServiceContext::factory()->create();
    $shift = Shift::query()->create([
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $worker->id,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(6),
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHours(2),
        'started_by' => $worker->id,
        'created_by' => $worker->id,
    ]);

    $task = ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Complete fluid chart',
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $worker->id,
        'shift_id' => $shift->id,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $worker->id,
    ]);

    return [$session, $shift, $task];
}

test('clock out applies embedded task updates before blocker evaluation', function () {
    [$session, $shift, $task] = attendanceTaskUpdateOpenSessionFor($this->worker);

    $this->actingAs($this->worker)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'task_updates' => [
                ['id' => $task->id, 'is_completed' => true],
            ],
            'handover' => [
                'meds_completed' => true,
                'shift_rating' => 'calm',
                'handover_notes' => 'Tasks completed before leaving.',
                'follow_up_needed' => false,
            ],
        ])
        ->assertSessionHas('success');

    expect($session->fresh()->status)->toBe('closed')
        ->and($task->fresh()->is_completed)->toBeTrue();

    expect(ShiftHandover::query()
        ->where('outgoing_shift_id', $shift->id)
        ->where('status', 'submitted')
        ->exists())->toBeTrue();
});

test('clock out with stale task updates returns a clean error with no partial close', function () {
    [$session, $shift, $task] = attendanceTaskUpdateOpenSessionFor($this->worker);

    $otherShift = Shift::query()->create([
        'client_id' => $shift->client_id,
        'service_context_id' => $shift->service_context_id,
        'user_id' => $this->worker->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(8),
        'status' => 'scheduled',
        'created_by' => $this->worker->id,
    ]);

    $staleTask = ShiftTask::query()->create([
        'shift_id' => $otherShift->id,
        'label' => 'Wrong shift task',
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->worker)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'task_updates' => [
                ['id' => $staleTask->id, 'is_completed' => true],
            ],
            'handover' => [
                'meds_completed' => true,
                'shift_rating' => 'calm',
                'handover_notes' => 'This handover should not persist.',
                'follow_up_needed' => false,
            ],
        ])
        ->assertSessionHasErrors(['task_updates']);

    expect($session->fresh()->status)->toBe('open')
        ->and($task->fresh()->is_completed)->toBeFalse()
        ->and(ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->exists())->toBeFalse();
});
