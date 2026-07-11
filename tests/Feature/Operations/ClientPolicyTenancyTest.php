<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

function grantClientPolicyTenancyPermission(User $user, string $permission): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_policy_tenancy_'.$user->id],
        ['label' => 'Client policy tenancy', 'level' => 10, 'type' => 'custom'],
    );
    $permissionModel = Permission::query()->firstOrCreate(
        ['key' => $permission],
        ['description' => $permission, 'group' => 'test', 'module' => 'Test'],
    );

    $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    $user->roles()->syncWithoutDetaching([$role->id]);
}

it('rejects a cross-organisation worker even if an assignment pivot exists', function () {
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);
    grantClientPolicyTenancyPermission($worker, 'clients.viewAssigned');
    $foreignClient = Client::factory()->create(['organization_id' => 2]);
    $foreignClient->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->get("/operations/clients/{$foreignClient->id}")
        ->assertForbidden();
});

it('still permits a same-organisation assigned worker', function () {
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);
    grantClientPolicyTenancyPermission($worker, 'clients.viewAssigned');
    $client = Client::factory()->create(['organization_id' => 1]);
    $client->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->get("/operations/clients/{$client->id}")
        ->assertOk();
});
