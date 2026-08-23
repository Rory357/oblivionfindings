<?php

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
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

    $this->manager = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);
    $manage = Permission::query()->where('key', 'timesheets.manageAny')->first();
    $view = Permission::query()->where('key', 'timesheets.viewAny')->first();
    $overrides = collect([$manage, $view])
        ->filter()
        ->mapWithKeys(fn (Permission $p) => [$p->id => ['allowed' => true]])
        ->all();
    $this->manager->permissionOverrides()->syncWithoutDetaching($overrides);

    $this->site = Site::factory()->create();
    $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    foreach ([$this->worker, $this->manager] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
    }
});

function openSessionFor(User $user, array $attributes = []): HrAttendanceSession
{
    return HrAttendanceSession::query()->create(array_merge([
        'tenant_id' => null,
        'user_id' => $user->id,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $user->id,
    ], $attributes));
}

test('managers see the on-clock-now board with stale sessions flagged', function () {
    openSessionFor($this->worker, ['clock_in_at' => now()->subHours(2)]);

    $staleWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staleWorker->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
    ]);
    openSessionFor($staleWorker, ['clock_in_at' => now()->subHours(20)]);

    $response = $this->actingAs($this->manager)->get('/attendance');

    $response->assertOk();
    $onClock = collect($response->viewData('page')['props']['onClockNow']);

    expect($onClock)->toHaveCount(2);

    $fresh = $onClock->firstWhere('user_id', $this->worker->id);
    $stale = $onClock->firstWhere('user_id', $staleWorker->id);

    expect($fresh['is_stale'])->toBeFalse()
        ->and($fresh['user_name'])->toBe($this->worker->name)
        ->and($stale['is_stale'])->toBeTrue();
});

test('workers do not receive the on-clock-now board', function () {
    openSessionFor($this->worker);

    $response = $this->actingAs($this->worker)->get('/attendance');

    $response->assertOk();
    expect($response->viewData('page')['props']['onClockNow'])->toHaveCount(0);
});

test('the sessions list is scoped to the requested week', function () {
    // Anchor to the worker-timezone week so the test is deterministic no
    // matter where "now" falls relative to the NZ Monday boundary.
    $tz = config('app.worker_timezone', 'Pacific/Auckland');
    $weekStartUtc = Carbon::now($tz)->startOfWeek(Carbon::MONDAY)->utc();

    $thisWeek = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'clock_in_at' => $weekStartUtc->copy()->addHours(1),
        'clock_out_at' => $weekStartUtc->copy()->addHours(3),
        'break_minutes' => 0,
        'status' => 'closed',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);
    $twoWeeksBack = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'clock_in_at' => $weekStartUtc->copy()->subDays(14)->addHours(1),
        'clock_out_at' => $weekStartUtc->copy()->subDays(14)->addHours(3),
        'break_minutes' => 0,
        'status' => 'closed',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);

    $current = $this->actingAs($this->worker)->get('/attendance');
    $current->assertOk();
    $ids = collect($current->viewData('page')['props']['sessions'])->pluck('id');
    expect($ids->all())->toBe([$thisWeek->id]);

    $weekParam = Carbon::now($tz)->subDays(14)->toDateString();
    $past = $this->actingAs($this->worker)->get('/attendance?week='.$weekParam);
    $past->assertOk();
    $pastProps = $past->viewData('page')['props'];
    expect(collect($pastProps['sessions'])->pluck('id')->all())->toBe([$twoWeeksBack->id])
        ->and($pastProps['filters']['week'])->toBe(
            Carbon::parse($weekParam, $tz)->startOfWeek(Carbon::MONDAY)->toDateString(),
        )
        ->and((int) $pastProps['totalSessions'])->toBe(2);
});

test('the open session carries its break state and tracked break events', function () {
    $session = openSessionFor($this->worker, [
        'clock_in_at' => now()->subHours(4),
        'break_started_at' => now()->subMinutes(10),
        'break_minutes' => 15,
        'break_count' => 2,
    ]);
    HrAttendanceBreakEvent::query()->create([
        'session_id' => $session->id,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2)->addMinutes(15),
        'minutes' => 15,
        'created_by' => $this->worker->id,
    ]);
    HrAttendanceBreakEvent::query()->create([
        'session_id' => $session->id,
        'started_at' => now()->subMinutes(10),
        'created_by' => $this->worker->id,
    ]);

    $response = $this->actingAs($this->worker)->get('/attendance');

    $response->assertOk();
    $open = $response->viewData('page')['props']['openSession'];
    expect($open['id'])->toBe($session->id)
        ->and($open['on_break'])->toBeTrue()
        ->and((int) $open['break_minutes'])->toBe(15)
        ->and($open['breaks'])->toHaveCount(2)
        ->and($open['breaks'][0]['minutes'])->toBe(15)
        ->and($open['breaks'][1]['ended_at'])->toBeNull();
});

test('handovers involving the user are listed with an incoming flag', function () {
    $incoming = ShiftHandover::factory()->create([
        'client_id' => $this->client->id,
        'incoming_staff_id' => $this->worker->id,
        'incoming_shift_id' => null,
    ]);
    // Someone else's handover must not leak into the list.
    ShiftHandover::factory()->create();

    $response = $this->actingAs($this->worker)->get('/attendance');

    $response->assertOk();
    $handovers = collect($response->viewData('page')['props']['handovers']);
    expect($handovers)->toHaveCount(1)
        ->and($handovers[0]['id'])->toBe($incoming->id)
        ->and($handovers[0]['incoming'])->toBeTrue()
        ->and($handovers[0]['status'])->toBe('submitted');
});

test('handovers follow the viewed staff member when a manager filters', function () {
    $workerHandover = ShiftHandover::factory()->create([
        'client_id' => $this->client->id,
        'incoming_staff_id' => $this->worker->id,
        'incoming_shift_id' => null,
    ]);
    // The manager's own handover must NOT appear while viewing the worker.
    ShiftHandover::factory()->create([
        'client_id' => $this->client->id,
        'outgoing_staff_id' => $this->manager->id,
    ]);

    $response = $this->actingAs($this->manager)
        ->get('/attendance?user_id='.$this->worker->id);

    $response->assertOk();
    $handovers = collect($response->viewData('page')['props']['handovers']);
    expect($handovers)->toHaveCount(1)
        ->and($handovers[0]['id'])->toBe($workerHandover->id)
        // `incoming` is relative to the viewed user, not the manager.
        ->and($handovers[0]['incoming'])->toBeTrue();
});

test('week hours sum the viewed user sessions for the current week only', function () {
    // 3h closed session yesterday-ish (within this week guaranteed by using today).
    HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'clock_in_at' => now()->startOfDay()->addHours(8),
        'clock_out_at' => now()->startOfDay()->addHours(11),
        'break_minutes' => 0,
        'status' => 'closed',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);
    // A session from a previous week must not count.
    HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'clock_in_at' => now()->subWeeks(2),
        'clock_out_at' => now()->subWeeks(2)->addHours(5),
        'break_minutes' => 0,
        'status' => 'closed',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);

    $response = $this->actingAs($this->worker)->get('/attendance');

    $response->assertOk();
    expect((float) $response->viewData('page')['props']['weekHours'])->toBe(3.0);
});
