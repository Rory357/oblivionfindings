<?php

use App\Models\Asset;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleBooking;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\Sites\Calendar\Providers\FleetServiceScheduleObligationProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function makeFleetMaintenanceUser(array $permissionKeys): User
{
    $user = User::factory()->create(['approved_at' => now()]);

    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
        );
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

/* ------------------------------------------------------------------ */
/*  Reference numbers (WO- / BK-) */
/* ------------------------------------------------------------------ */

it('assigns a WO- reference number to a new work order', function () {
    $order = FleetWorkOrder::factory()->create(['status' => 'open']);

    expect($order->reference_number)->toStartWith('WO-'.now()->year.'-');
});

it('assigns a BK- reference number to a new vehicle booking', function () {
    $booking = FleetVehicleBooking::factory()->create(['status' => 'pending']);

    expect($booking->reference_number)->toStartWith('BK-'.now()->year.'-');
});

it('keeps an explicitly supplied work order reference', function () {
    $order = FleetWorkOrder::factory()->create(['reference_number' => 'WO-2020-9999']);

    expect($order->reference_number)->toBe('WO-2020-9999');
});

/* ------------------------------------------------------------------ */
/*  Calendar: FleetServiceScheduleObligationProvider */
/* ------------------------------------------------------------------ */

it('surfaces an active service schedule as a calendar obligation', function () {
    $site = Site::factory()->create();
    $asset = Asset::factory()->vehicle()->forSite($site)->create(['name' => 'Hiace 1']);

    $schedule = FleetServiceSchedule::create([
        'asset_id' => $asset->id,
        'name' => '10,000 km service',
        'interval_days' => 180,
        'next_due_at' => now()->addDays(3),
        'is_active' => true,
    ]);

    $items = (new FleetServiceScheduleObligationProvider)
        ->obligations([$site->id], Carbon::now()->subDay(), Carbon::now()->addMonth());

    expect($items)->toHaveCount(1)
        ->and($items[0]->id)->toBe("fleet-service-{$schedule->id}")
        ->and($items[0]->source)->toBe('asset')
        ->and($items[0]->title)->toContain('Service due — Hiace 1')
        ->and($items[0]->link)->toBe('/fleet-assets/maintenance/schedules');
});

it('skips inactive schedules and schedules for other sites', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();

    $inactiveAsset = Asset::factory()->vehicle()->forSite($site)->create();
    FleetServiceSchedule::create([
        'asset_id' => $inactiveAsset->id,
        'name' => 'Paused plan',
        'next_due_at' => now()->addDays(3),
        'is_active' => false,
    ]);

    $elsewhereAsset = Asset::factory()->vehicle()->forSite($otherSite)->create();
    FleetServiceSchedule::create([
        'asset_id' => $elsewhereAsset->id,
        'name' => 'Other site plan',
        'next_due_at' => now()->addDays(3),
        'is_active' => true,
    ]);

    $items = (new FleetServiceScheduleObligationProvider)
        ->obligations([$site->id], Carbon::now()->subDay(), Carbon::now()->addMonth());

    expect($items)->toBeEmpty();
});

/* ------------------------------------------------------------------ */
/*  Tasks: FleetMaintenanceProvider */
/* ------------------------------------------------------------------ */

it('returns an overdue work order on /tasks for a permitted user', function () {
    $user = makeFleetMaintenanceUser(['fleet.viewAny']);

    $order = FleetWorkOrder::factory()->create([
        'status' => 'open',
        'priority' => 'high',
        'due_at' => now()->subDays(2),
    ]);

    $items = (new TaskAggregator)->itemsFor($user, []);
    $item = collect($items)->first(fn ($i) => $i->id === 'fleet_work_order-'.$order->id);

    expect($item)->not->toBeNull()
        ->and($item->ref)->toStartWith('WO-')
        ->and($item->isOverdue())->toBeTrue()
        ->and($item->link)->toBe("/fleet-assets/maintenance/work-orders/{$order->id}")
        ->and(collect((new TaskAggregator)->sourcesFor($user))->pluck('key'))->toContain('fleet_maintenance');
});

it('returns a service schedule due this week as a task', function () {
    $user = makeFleetMaintenanceUser(['assets.viewAny']);

    $asset = Asset::factory()->vehicle()->create(['name' => 'Hiace 2']);
    $schedule = FleetServiceSchedule::create([
        'asset_id' => $asset->id,
        'name' => 'WOF prep service',
        'next_due_at' => now()->addDays(3),
        'is_active' => true,
    ]);

    $items = (new TaskAggregator)->itemsFor($user, []);
    $item = collect($items)->first(fn ($i) => $i->id === 'fleet_service_schedule-'.$schedule->id);

    expect($item)->not->toBeNull()
        ->and($item->title)->toContain('Hiace 2')
        ->and($item->link)->toBe('/fleet-assets/maintenance/schedules');
});

it('keeps composite fleet task identities distinct through detail and following actions', function () {
    $user = makeFleetMaintenanceUser(['fleet.viewAny']);
    $recordId = 9001;
    $asset = Asset::factory()->vehicle()->create(['name' => 'Shared identity vehicle']);
    $order = FleetWorkOrder::factory()->create([
        'id' => $recordId,
        'asset_id' => $asset->id,
        'status' => 'open',
        'due_at' => now()->subDay(),
    ]);
    $schedule = FleetServiceSchedule::unguarded(
        fn () => FleetServiceSchedule::create([
            'id' => $recordId,
            'asset_id' => $asset->id,
            'name' => 'Shared identity service',
            'next_due_at' => now()->addDays(2),
            'is_active' => true,
        ]),
    );

    $this->actingAs($user)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'fleet_work_order',
            'id' => $recordId,
        ]))
        ->assertOk()
        ->assertJsonPath('item.id', 'fleet_work_order-'.$order->id)
        ->assertJsonPath('item.link', "/fleet-assets/maintenance/work-orders/{$order->id}");

    $this->actingAs($user)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'fleet_service_schedule',
            'id' => $recordId,
        ]))
        ->assertOk()
        ->assertJsonPath('item.id', 'fleet_service_schedule-'.$schedule->id)
        ->assertJsonPath('item.link', '/fleet-assets/maintenance/schedules');

    $this->actingAs($user)
        ->post("/tasks/fleet_work_order/{$recordId}/watch", ['watching' => true])
        ->assertRedirect();
    $this->actingAs($user)
        ->post("/tasks/fleet_service_schedule/{$recordId}/watch", ['watching' => true])
        ->assertRedirect();

    $this->assertDatabaseHas('task_watchers', [
        'source' => 'fleet_work_order',
        'item_id' => $recordId,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('task_watchers', [
        'source' => 'fleet_service_schedule',
        'item_id' => $recordId,
        'user_id' => $user->id,
    ]);

    $followingIds = collect((new TaskAggregator)->itemsFor($user, [
        'following' => true,
    ]))->pluck('id');

    expect($followingIds)
        ->toContain('fleet_work_order-'.$recordId)
        ->toContain('fleet_service_schedule-'.$recordId);

    TaskWatcher::query()->create([
        'source' => 'fleet_maintenance',
        'item_id' => $recordId,
        'user_id' => $user->id,
    ]);
    $legacyUser = makeFleetMaintenanceUser(['fleet.viewAny']);
    TaskWatcher::query()->create([
        'source' => 'fleet_maintenance',
        'item_id' => $recordId,
        'user_id' => $legacyUser->id,
    ]);

    $workOrderWatcherIds = collect(
        (new TaskAggregator)->authorizedWatcherIdsFor(
            'fleet_work_order',
            $recordId,
        ),
    )->sort()->values()->all();
    $scheduleWatcherIds = collect(
        (new TaskAggregator)->authorizedWatcherIdsFor(
            'fleet_service_schedule',
            $recordId,
        ),
    )->sort()->values()->all();
    $legacyFollowingIds = collect((new TaskAggregator)->itemsFor($legacyUser, [
        'following' => true,
    ]))->pluck('id');

    expect($workOrderWatcherIds)->toBe(collect([
        $user->id,
        $legacyUser->id,
    ])->sort()->values()->all())
        ->and($scheduleWatcherIds)->toBe([$user->id])
        ->and($legacyFollowingIds)->toContain('fleet_work_order-'.$recordId)
        ->not->toContain('fleet_service_schedule-'.$recordId);

    $this->actingAs($user)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'fleet_work_order',
            'id' => $recordId,
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'watchers');

    $this->assertDatabaseHas('task_watchers', [
        'source' => 'fleet_maintenance',
        'item_id' => $recordId,
        'user_id' => $user->id,
    ]);

    $order->update(['status' => 'completed']);
    $legacyOpenIds = collect((new TaskAggregator)->itemsFor($legacyUser, [
        'following' => true,
    ]))->pluck('id');
    $legacyHistoryIds = collect((new TaskAggregator)->itemsFor($legacyUser, [
        'following' => true,
        'include_done' => true,
    ]))->pluck('id');

    expect($legacyOpenIds)
        ->not->toContain('fleet_service_schedule-'.$recordId)
        ->and($legacyHistoryIds)
        ->toContain('fleet_work_order-'.$recordId)
        ->not->toContain('fleet_service_schedule-'.$recordId);
});

it('hides fleet maintenance items from a user without fleet or asset permissions', function () {
    $user = makeFleetMaintenanceUser([]);

    FleetWorkOrder::factory()->create([
        'status' => 'open',
        'due_at' => now()->subDay(),
    ]);

    $aggregator = new TaskAggregator;

    expect(collect($aggregator->sourcesFor($user))->pluck('key'))->not->toContain('fleet_maintenance')
        ->and(collect($aggregator->itemsFor($user, []))
            ->first(fn ($i) => str_starts_with($i->id, 'fleet_work_order-')))->toBeNull();
});

it('excludes completed work orders and far-future due dates', function () {
    $user = makeFleetMaintenanceUser(['fleet.viewAny']);

    $done = FleetWorkOrder::factory()->create([
        'status' => 'completed',
        'due_at' => now()->subDay(),
    ]);
    $farOut = FleetWorkOrder::factory()->create([
        'status' => 'open',
        'due_at' => now()->addDays(30),
    ]);

    $ids = collect((new TaskAggregator)->itemsFor($user, []))->pluck('id');

    expect($ids)->not->toContain('fleet_work_order-'.$done->id)
        ->and($ids)->not->toContain('fleet_work_order-'.$farOut->id);
});
