<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

test('legacy rostering get route redirects to the operations surface', function () {
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', 'support_worker')->first();
    if ($role) {
        $staff->roles()->syncWithoutDetaching([$role->id]);
    }

    $this->actingAs($staff)
        ->get('/rostering')
        ->assertStatus(301)
        ->assertRedirect(url('/operations/rostering'));
});

test('frontline users landing on canonical rostering are redirected to my day', function () {
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', 'support_worker')->first();
    if ($role) {
        $staff->roles()->syncWithoutDetaching([$role->id]);
    }

    $this->actingAs($staff)
        ->get('/operations/rostering')
        ->assertRedirect(route('my-day'));
});

test('orphaned staff training and competency permissions are not seeded', function () {
    expect(Permission::query()
        ->whereIn('key', [
            'staff.training.viewAny',
            'staff.training.manage',
            'staff.competency.assess',
        ])
        ->exists())->toBeFalse();
});
