<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\ShiftTaskProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;

test('shift tasks expose the rostered staff assignee while enforcing canonical Site access', function () {
    $this->seed(RbacSeeder::class);

    $visibleSite = Site::factory()->create(['name' => 'Visible Roster Site']);
    $hiddenSite = Site::factory()->create(['name' => 'Hidden Roster Site']);
    $viewer = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);
    $permission = Permission::query()->where('key', 'shifts.manageAny')->firstOrFail();
    $viewer->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'position_role' => 'coordinator',
        'primary_site_id' => $visibleSite->id,
        'secondary_site_ids' => [],
    ]);

    $worker = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $visibleSite->id,
        'secondary_site_ids' => [],
    ]);
    $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
    $shift = Shift::factory()->create([
        'site_id' => $visibleSite->id,
        'client_id' => $visibleClient->id,
        'user_id' => $worker->id,
        'created_by' => $viewer->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(4),
        'status' => 'scheduled',
    ]);
    ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Pack medication bag',
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    $hiddenWorker = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $hiddenWorker->id,
        'primary_site_id' => $hiddenSite->id,
        'secondary_site_ids' => [],
    ]);
    $hiddenClient = Client::factory()->create(['site_id' => $hiddenSite->id]);
    $hiddenShift = Shift::factory()->create([
        'site_id' => $hiddenSite->id,
        'client_id' => $hiddenClient->id,
        'user_id' => $hiddenWorker->id,
        'created_by' => $hiddenWorker->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(4),
        'status' => 'scheduled',
    ]);
    $hiddenTask = ShiftTask::query()->create([
        'shift_id' => $hiddenShift->id,
        'label' => 'Hidden Site task',
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    $tasks = app(ShiftTaskProvider::class)->authorizedTasks($viewer);
    $directHiddenLookup = app(ShiftTaskProvider::class)->authorizedTasks($viewer, [
        'id' => $hiddenTask->id,
    ]);

    expect($tasks)->toHaveCount(1)
        ->and(collect($tasks)->pluck('title'))->not->toContain('Hidden Site task')
        ->and($directHiddenLookup)->toBeEmpty()
        ->and($tasks[0]->assignee)->toBe([
            'id' => $worker->id,
            'name' => $worker->name,
        ]);
});

it('renders the rostered worker without loading an undefined shift relationship', function () {
    $this->seed(RbacSeeder::class);
    $worker = User::factory()->create(['approved_at' => now()]);
    $permission = Permission::query()->where('key', 'shifts.viewAny')->firstOrFail();
    $worker->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = Shift::factory()->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'starts_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);
    $task = ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Complete the shift safety check',
        'is_completed' => false,
    ]);

    $items = (new ShiftTaskProvider)->authorizedTasks($worker);

    expect($items)->toHaveCount(1)
        ->and($items[0]->id)->toBe('shift_task-'.$task->id)
        ->and($items[0]->assignee)->toBe([
            'id' => $worker->id,
            'name' => $worker->name,
        ]);
});

it('counts zero and populated rostered shift tasks for the navigation badge', function () {
    $this->seed(RbacSeeder::class);
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $worker->roles()->attach(
        Role::query()->where('name', 'support_worker')->firstOrFail(),
    );
    $site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $aggregator = new TaskAggregator([new ShiftTaskProvider]);

    expect($aggregator->badgeCountFor($worker))->toBe(0);

    $shift = Shift::factory()->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'starts_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);
    ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Check the rostered client plan',
        'is_completed' => false,
    ]);

    $projection = $aggregator->navigationBadgeFor($worker);

    expect($aggregator->badgeCountFor($worker))->toBe(1)
        ->and($projection)->toBe([
            'view' => true,
            'badge' => 1,
            'degraded' => false,
        ]);
});
