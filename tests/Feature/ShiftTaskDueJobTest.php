<?php

use App\Jobs\ShiftTaskDueJob;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftTaskDueNotification;
use App\Services\Facility\FacilitySignalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    Carbon::setTestNow(Carbon::parse('2026-06-01 10:05:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('notifies the assigned worker once and raises a snoozable My Day alert when a timed task is due', function () {
    Notification::fake();

    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create(['first_name' => 'Ari', 'last_name' => 'Kauri']);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'status' => 'scheduled',
        'starts_at' => Carbon::parse('2026-06-01 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-01 13:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $task = $shift->tasks()->create([
        'label' => 'Give medication prompt',
        'scheduled_time' => '10:00',
        'sort_order' => 0,
    ]);

    app(ShiftTaskDueJob::class)->handle(app(FacilitySignalService::class));

    Notification::assertSentTo($worker, ShiftTaskDueNotification::class);
    expect($task->fresh()->reminder_sent_at)->not->toBeNull();

    $alert = ControlRoomAlert::query()
        ->where('source', 'shift_task')
        ->where('alert_type', 'Shift task due')
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->assigned_to_user_id)->toBe($worker->id)
        ->and($alert->client_id)->toBe($client->id)
        ->and($alert->context['shift_task_id'] ?? null)->toBe($task->id);

    Notification::fake();

    app(ShiftTaskDueJob::class)->handle(app(FacilitySignalService::class));

    Notification::assertNothingSent();
    expect(ControlRoomAlert::query()->where('source', 'shift_task')->where('alert_type', 'Shift task due')->count())
        ->toBe(1);
});

it('notifies overnight shift tasks that roll into the current day', function () {
    Notification::fake();

    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create(['first_name' => 'Mere', 'last_name' => 'Rangi']);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'status' => 'scheduled',
        'starts_at' => Carbon::parse('2026-05-31 22:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-01 06:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $task = $shift->tasks()->create([
        'label' => 'Overnight repositioning',
        'scheduled_time' => '02:00',
        'sort_order' => 0,
    ]);

    app(ShiftTaskDueJob::class)->handle(app(FacilitySignalService::class));

    Notification::assertSentTo($worker, ShiftTaskDueNotification::class);
    expect($task->fresh(['shift'])->scheduledFor()?->timezone('Pacific/Auckland')->format('Y-m-d H:i'))
        ->toBe('2026-06-01 02:00')
        ->and($task->fresh()->reminder_sent_at)->not->toBeNull();
});

it('skips completed unassigned draft and future timed tasks', function () {
    Notification::fake();

    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create();

    $dueShift = fn (array $overrides = []) => Shift::factory()->create(array_merge([
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'status' => 'scheduled',
        'starts_at' => Carbon::parse('2026-06-01 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-01 13:00:00', 'Pacific/Auckland')->utc(),
    ], $overrides));

    $dueShift()->tasks()->create(['label' => 'Completed', 'scheduled_time' => '10:00', 'is_completed' => true]);
    $dueShift(['user_id' => null])->tasks()->create(['label' => 'Unassigned', 'scheduled_time' => '10:00']);
    $dueShift(['status' => 'draft'])->tasks()->create(['label' => 'Draft', 'scheduled_time' => '10:00']);
    $dueShift()->tasks()->create(['label' => 'Future', 'scheduled_time' => '11:00']);

    app(ShiftTaskDueJob::class)->handle(app(FacilitySignalService::class));

    Notification::assertNothingSent();
    expect(ControlRoomAlert::query()->where('source', 'shift_task')->count())->toBe(0);
});
