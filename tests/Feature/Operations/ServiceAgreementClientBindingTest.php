<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\User;

function grantServiceAgreementBindingPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'service_agreement_binding_'.$user->id],
        ['label' => 'Service Agreement Binding', 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

it('rejects a client from another organisation when creating a service agreement', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    grantServiceAgreementBindingPermissions($actor, ['service_agreements.create']);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($actor)
        ->post('/operations/service-agreements', [
            'client_id' => $foreignClient->id,
            'title' => 'Foreign agreement',
            'agreement_type' => 'individualised_funding',
        ])
        ->assertSessionHasErrors('client_id');

    expect(ServiceAgreement::query()->where('title', 'Foreign agreement')->exists())
        ->toBeFalse();
});

it('rejects moving a service agreement to a client from another organisation', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    grantServiceAgreementBindingPermissions($actor, ['service_agreements.update']);
    $ownClient = Client::factory()->create(['organization_id' => 1]);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);
    $agreement = ServiceAgreement::factory()->create([
        'organization_id' => 1,
        'client_id' => $ownClient->id,
    ]);

    $this->actingAs($actor)
        ->put("/operations/service-agreements/{$agreement->id}", [
            'client_id' => $foreignClient->id,
        ])
        ->assertSessionHasErrors('client_id');

    expect($agreement->fresh()->client_id)->toBe($ownClient->id);
});
