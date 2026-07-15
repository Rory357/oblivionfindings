<?php

use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\Tasks\Providers\ShiftTaskProvider;
use Database\Seeders\RbacSeeder;

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
