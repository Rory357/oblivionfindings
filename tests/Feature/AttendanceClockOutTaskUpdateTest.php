<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

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
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $serviceContext = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true]);
    $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $serviceContext->id]);
    HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
        'secondary_site_ids' => [], 'is_active' => true,
        'start_date' => now(config('app.worker_timezone', 'Pacific/Auckland'))->subMonth()->toDateString(),
        'end_date' => null, 'created_by' => $worker->id, 'updated_by' => $worker->id]);
    $client->supportWorkers()->syncWithoutDetaching([$worker->id]);
    $shift = Shift::query()->create([
        'site_id' => $site->id,
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

    return [$session, $shift, $task->fresh()];
}

test('clock out applies embedded task updates before blocker evaluation', function () {
    [$session, $shift, $task] = attendanceTaskUpdateOpenSessionFor($this->worker);

    $this->actingAs($this->worker)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'task_updates' => [
                ['id' => $task->id, 'is_completed' => true, 'expected_version' => $task->version],
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
        ->sole()->status)->toBe('draft');
    expect(ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->sole()->submitted_at)->toBeNull();
});

test('clock out conceals a foreign shift task with no partial close', function () {
    [$session, $shift, $task] = attendanceTaskUpdateOpenSessionFor($this->worker);

    $otherShift = Shift::query()->create([
        'site_id' => $shift->site_id,
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
        ->assertNotFound();

    expect($session->fresh()->status)->toBe('open')
        ->and($task->fresh()->is_completed)->toBeFalse()
        ->and(ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->exists())->toBeFalse();
});

test('clock out with stale task updates returns a clean error with no partial close', function () {
    [$session, $shift, $task] = attendanceTaskUpdateOpenSessionFor($this->worker);
    $before = $task->getRawOriginal();
    $this->actingAs($this->worker)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'task_updates' => [
                ['id' => $task->id, 'is_completed' => true, 'expected_version' => $task->version + 1],
            ],
            'handover' => [
                'meds_completed' => true, 'shift_rating' => 'calm',
                'handover_notes' => 'This stale task handover should not persist.', 'follow_up_needed' => false,
            ],
        ])
        ->assertSessionHasErrors(['task_updates']);

    expect($session->fresh()->status)->toBe('open')
        ->and($task->fresh()->getRawOriginal())->toBe($before)
        ->and(ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->exists())->toBeFalse();
});
