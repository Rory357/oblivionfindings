<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\ShiftTaskDueJob;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Channels\PushChannel;
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

function shiftTaskDueWorkerAtSite(Site $site, array $profileOverrides = []): User
{
    $worker = User::factory()->frontlineWorker()->create();

    HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => '2025-01-01',
        'end_date' => null,
    ], $profileOverrides));

    return $worker;
}

it('notifies the assigned worker once and raises a snoozable My Day alert when a timed task is due', function () {
    Notification::fake();

    $site = Site::factory()->create();
    $worker = shiftTaskDueWorkerAtSite($site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Ari',
        'last_name' => 'Kauri',
    ]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
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

    $site = Site::factory()->create();
    $worker = shiftTaskDueWorkerAtSite($site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Mere',
        'last_name' => 'Rangi',
    ]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
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

    $site = Site::factory()->create();
    $worker = shiftTaskDueWorkerAtSite($site);
    $client = Client::factory()->create(['site_id' => $site->id]);

    $dueShift = fn (array $overrides = []) => Shift::factory()->create(array_merge([
        'client_id' => $client->id,
        'site_id' => $site->id,
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

it('does not claim notify or signal due tasks assigned to workers without current Site access', function () {
    Notification::fake();

    $shiftSite = Site::factory()->create();
    $remoteSite = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $shiftSite->id]);
    $remoteWorker = shiftTaskDueWorkerAtSite($remoteSite);
    $inactiveWorker = shiftTaskDueWorkerAtSite($shiftSite, ['is_active' => false]);

    $makeDueTask = function (User $worker, string $label) use ($client, $shiftSite) {
        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $shiftSite->id,
            'user_id' => $worker->id,
            'status' => 'scheduled',
            'starts_at' => Carbon::parse('2026-06-01 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-06-01 13:00:00', 'Pacific/Auckland')->utc(),
        ])->tasks()->create([
            'label' => $label,
            'scheduled_time' => '10:00',
            'sort_order' => 0,
        ]);
    };

    $remoteTask = $makeDueTask($remoteWorker, 'Remote worker task');
    $inactiveTask = $makeDueTask($inactiveWorker, 'Inactive worker task');

    app(ShiftTaskDueJob::class)->handle(app(FacilitySignalService::class));

    Notification::assertNothingSent();
    expect($remoteTask->fresh()->reminder_sent_at)->toBeNull()
        ->and($inactiveTask->fresh()->reminder_sent_at)->toBeNull()
        ->and(ControlRoomAlert::query()->where('source', 'shift_task')->count())->toBe(0);
});

it('revalidates the current recipient and Shift at queued notification delivery time', function () {
    $site = Site::factory()->create();
    $worker = shiftTaskDueWorkerAtSite($site);
    $replacement = shiftTaskDueWorkerAtSite($site);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'status' => 'scheduled',
        'starts_at' => Carbon::parse('2026-06-01 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-01 13:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $task = $shift->tasks()->create([
        'label' => 'Revalidate medication prompt',
        'scheduled_time' => '10:00',
        'sort_order' => 0,
    ]);

    $valid = new ShiftTaskDueNotification($task);

    expect($valid->via($worker))->toBe(['database', 'mail', PushChannel::class])
        ->and($valid->toArray($worker))
        ->toMatchArray([
            'type' => 'shift_task_due',
            'shift_id' => $shift->id,
            'shift_task_id' => $task->id,
            'client_id' => $client->id,
        ]);

    $reassigned = new ShiftTaskDueNotification($task);
    $shift->update(['user_id' => $replacement->id]);

    expect($reassigned->via($worker))->toBe([])
        ->and($reassigned->shouldSend($worker, 'mail'))->toBeFalse();

    $shift->update(['user_id' => $worker->id]);
    $revoked = new ShiftTaskDueNotification($task);

    expect($revoked->via($worker))->toBe(['database', 'mail', PushChannel::class]);

    $worker->hrEmployeeProfile()->update(['is_active' => false]);

    expect($revoked->shouldSend($worker, 'database'))->toBeFalse();
});

it('uses a fresh Site authorization boundary for each due task in the scheduler batch', function () {
    $site = Site::factory()->create();
    $worker = shiftTaskDueWorkerAtSite($site);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'status' => 'scheduled',
        'starts_at' => Carbon::parse('2026-06-01 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-01 13:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $firstTask = $shift->tasks()->create([
        'label' => 'First due task',
        'scheduled_time' => '09:55',
        'sort_order' => 0,
    ]);
    $secondTask = $shift->tasks()->create([
        'label' => 'Second due task',
        'scheduled_time' => '10:00',
        'sort_order' => 1,
    ]);
    UserNotificationPreference::query()->create([
        'user_id' => $worker->id,
        'key' => 'shift_task_due',
        'enabled' => false,
        'channel_inapp' => false,
        'channel_email' => false,
        'channel_push' => false,
    ]);
    $signalService = Mockery::mock(FacilitySignalService::class);
    $signalService->shouldReceive('emitShiftTaskDue')
        ->once()
        ->andReturnUsing(function () use ($worker): void {
            HrEmployeeProfile::query()
                ->where('user_id', $worker->id)
                ->update(['is_active' => false]);
        });

    app(ShiftTaskDueJob::class)->handle($signalService);

    expect($firstTask->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($secondTask->fresh()->reminder_sent_at)->toBeNull();
});
