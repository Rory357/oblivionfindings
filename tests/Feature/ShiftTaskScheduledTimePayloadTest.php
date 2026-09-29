<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Resources\MyShiftResource;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
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
    // My Day shows the clock session for a shift at the worker's current
    // Site, and the shift's client tasks only when the worker may view that
    // client. The shift has no site_id of its own: it resolves through the
    // client's home Site.
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $viewAssigned = Permission::query()->firstOrCreate(
        ['key' => 'clients.viewAssigned'],
        ['description' => 'clients.viewAssigned'],
    );
    $worker->permissionOverrides()->syncWithoutDetaching([
        $viewAssigned->id => ['allowed' => true],
    ]);
    $client->supportWorkers()->attach($worker->id);
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
