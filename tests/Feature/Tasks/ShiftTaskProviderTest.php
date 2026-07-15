<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\Tasks\Providers\ShiftTaskProvider;
use Database\Seeders\RbacSeeder;

test('shift tasks expose the rostered staff assignee through the canonical shift relation', function () {
    $this->seed(RbacSeeder::class);

    $admin = User::factory()->create([
        'role' => 'admin',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $admin->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'admin')->firstOrFail()->id,
    ]);

    $worker = User::factory()->create(['organization_id' => 1]);
    $shift = Shift::factory()->create([
        'user_id' => $worker->id,
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

    $foreignWorker = User::factory()->create(['organization_id' => 2]);
    $foreignShift = Shift::factory()->create([
        'organization_id' => 2,
        'client_id' => Client::factory()->create(['organization_id' => 2])->id,
        'user_id' => $foreignWorker->id,
        'created_by' => $foreignWorker->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(4),
        'status' => 'scheduled',
    ]);
    ShiftTask::query()->create([
        'shift_id' => $foreignShift->id,
        'label' => 'Foreign tenant task',
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    $tasks = app(ShiftTaskProvider::class)->tasks($admin);

    expect($tasks)->toHaveCount(1)
        ->and(collect($tasks)->pluck('title'))->not->toContain('Foreign tenant task')
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
    $shift = Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);
    $task = ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Complete the shift safety check',
        'is_completed' => false,
    ]);

    $items = (new ShiftTaskProvider)->tasks($worker);

    expect($items)->toHaveCount(1)
        ->and($items[0]->id)->toBe('shift_task-'.$task->id)
        ->and($items[0]->assignee)->toBe([
            'id' => $worker->id,
            'name' => $worker->name,
        ]);
});
