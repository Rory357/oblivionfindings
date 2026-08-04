<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\Site;
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

function serviceAgreementActorForSite(Site $site, array $permissionKeys): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    grantServiceAgreementBindingPermissions($actor, $permissionKeys);

    HrEmployeeProfile::query()->create([
        'user_id' => $actor->id,
        'employee_number' => 'EMP-SA-'.$actor->id,
        'work_email' => $actor->email,
        'position_title' => 'Service Agreement Manager',
        'position_role' => 'manager',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $actor;
}

it('rejects an unassigned Site client when creating a service agreement', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = serviceAgreementActorForSite($assignedSite, ['service_agreements.create']);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);

    $this->actingAs($actor)
        ->post('/operations/service-agreements', [
            'client_id' => $otherClient->id,
            'title' => 'Other Site agreement',
            'agreement_type' => 'individualised_funding',
        ])
        ->assertForbidden();

    expect(ServiceAgreement::query()->where('title', 'Other Site agreement')->exists())
        ->toBeFalse();
});

it('rejects moving an accessible service agreement to another Site client', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = serviceAgreementActorForSite($assignedSite, ['service_agreements.update']);
    $assignedClient = Client::factory()->create(['site_id' => $assignedSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $agreement = ServiceAgreement::factory()->create([
        'client_id' => $assignedClient->id,
    ]);

    $this->actingAs($actor)
        ->put("/operations/service-agreements/{$agreement->id}", [
            'client_id' => $otherClient->id,
        ])
        ->assertForbidden();

    expect($agreement->fresh()->client_id)->toBe($assignedClient->id);
});

it('fails closed when a service agreement client has no canonical Site', function () {
    $assignedSite = Site::factory()->create();
    $actor = serviceAgreementActorForSite($assignedSite, ['service_agreements.create']);
    $clientWithoutSite = Client::factory()->create(['site_id' => null]);

    $this->actingAs($actor)
        ->post('/operations/service-agreements', [
            'client_id' => $clientWithoutSite->id,
            'title' => 'Orphan agreement',
            'agreement_type' => 'individualised_funding',
        ])
        ->assertForbidden();

    expect(ServiceAgreement::query()->where('title', 'Orphan agreement')->exists())
        ->toBeFalse();
});
