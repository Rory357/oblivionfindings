<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Permission;
use App\Models\Role;
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
