<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function hrRoleUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

test('users without hr time permission cannot clock in via hr time routes', function () {
    $user = hrRoleUser('support_worker');

    $this->actingAs($user)
        ->post('/hr/time/clock-in')
        ->assertForbidden();
});

test('hr users can access the hr time dashboard', function () {
    $user = hrRoleUser('hr');

    $this->actingAs($user)
        ->get('/hr/time')
        ->assertOk();
});
