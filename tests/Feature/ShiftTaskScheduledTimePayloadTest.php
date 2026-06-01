<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Http\Resources\MyShiftResource;
use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    Carbon::setTestNow(Carbon::parse('2026-06-01 23:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('rolls a timed shift task past midnight in the worker timezone', function () {
    $shift = Shift::factory()->create([
        'starts_at' => Carbon::parse('2026-06-01 22:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-02 06:00:00', 'Pacific/Auckland')->utc(),
    ]);

    $task = $shift->tasks()->create([
        'label' => 'Overnight repositioning',
        'scheduled_time' => '02:00',
        'sort_order' => 0,
    ]);

    expect($task->fresh(['shift'])->scheduledFor()?->timezone('Pacific/Auckland')->format('Y-m-d H:i'))
        ->toBe('2026-06-02 02:00');
});

it('emits scheduled_for from MyShiftResource for timed tasks', function () {
    $shift = Shift::factory()->create([
        'starts_at' => Carbon::parse('2026-06-01 22:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-02 06:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $task = $shift->tasks()->create([
        'label' => 'Overnight fluids',
        'scheduled_time' => '02:00',
        'sort_order' => 0,
    ]);

    $payload = MyShiftResource::fromShift($shift->fresh(['tasks']), Carbon::now('Pacific/Auckland'));

    expect($payload['tasks'][0]['scheduled_for'])
        ->toBe($task->fresh(['shift'])->scheduledFor()?->toIso8601String());
});

it('emits scheduled_for for the open clock-session task map on My Day', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create();
    $shift = Shift::factory()->assignedToday(
        $worker,
        Carbon::parse('2026-06-01 22:00:00', 'Pacific/Auckland')
    )->published()->create([
        'client_id' => $client->id,
        'status' => 'in_progress',
    ]);
    $task = $shift->tasks()->create([
        'label' => 'Overnight turn',
        'scheduled_time' => '02:00',
        'sort_order' => 0,
    ]);

    HrAttendanceSession::query()->create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'shift_id' => $shift->id,
        'clock_in_at' => Carbon::parse('2026-06-01 22:00:00', 'Pacific/Auckland')->utc(),
        'status' => 'open',
        'source' => 'web',
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('clock.open_session.tasks.0.id', $task->id)
            ->where('clock.open_session.tasks.0.scheduled_for', $task->fresh(['shift'])->scheduledFor()?->toIso8601String())
        );
});
