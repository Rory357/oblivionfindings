<?php

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

    $tasks = app(ShiftTaskProvider::class)->tasks($admin);

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->assignee)->toBe([
            'id' => $worker->id,
            'name' => $worker->name,
        ]);
});
