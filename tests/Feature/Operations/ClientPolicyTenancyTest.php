<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;

function grantClientPolicySitePermission(User $user, string $permission): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_policy_site_'.$user->id],
        ['label' => 'Client policy Site access', 'level' => 10, 'type' => 'custom'],
    );
    $permissionModel = Permission::query()->firstOrCreate(
        ['key' => $permission],
        ['description' => $permission, 'group' => 'test', 'module' => 'Test'],
    );

    $role->permissions()->syncWithoutDetaching([$permissionModel->id]);
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function assignClientPolicyWorkerToSite(User $worker, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

it('rejects a poisoned client assignment outside the workers current Site access', function () {
    $workerSite = Site::factory()->create(['name' => 'Worker Home']);
    $clientSite = Site::factory()->create(['name' => 'Other Home']);
    $worker = User::factory()->create(['role' => 'support_worker']);
    grantClientPolicySitePermission($worker, 'clients.viewAssigned');
    assignClientPolicyWorkerToSite($worker, $workerSite);

    $client = Client::factory()->create(['site_id' => $clientSite->id]);
    $client->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->get("/operations/clients/{$client->id}")
        ->assertForbidden();
});

it('permits an assigned worker with current access to the clients Site', function () {
    $site = Site::factory()->create();
    $worker = User::factory()->create(['role' => 'support_worker']);
    grantClientPolicySitePermission($worker, 'clients.viewAssigned');
    assignClientPolicyWorkerToSite($worker, $site);

    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach($worker->id);

    $this->actingAs($worker)
        ->get("/operations/clients/{$client->id}")
        ->assertOk();
});

it('fails closed for Site-less clients even with application-wide staff permission', function () {
    $manager = User::factory()->create(['role' => 'provider_manager']);
    grantClientPolicySitePermission($manager, 'clients.viewAny');
    $client = Client::factory()->create(['site_id' => null]);

    expect($manager->can('view', $client))->toBeFalse();

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}")
        ->assertForbidden();
});

it('allows explicit application-wide permission across active Sites only', function () {
    $manager = User::factory()->create(['role' => 'provider_manager']);
    grantClientPolicySitePermission($manager, 'clients.viewAny');
    $activeSite = Site::factory()->create();
    $inactiveSite = Site::factory()->create(['is_active' => false]);
    $activeClient = Client::factory()->create(['site_id' => $activeSite->id]);
    $inactiveClient = Client::factory()->create(['site_id' => $inactiveSite->id]);

    expect($manager->can('view', $activeClient))->toBeTrue()
        ->and($manager->can('view', $inactiveClient))->toBeFalse();
});

it('uses a persisted portal role and pivot as ownership independently of Site storage', function () {
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['label' => 'Client', 'level' => 1, 'type' => 'system'],
    );
    $portalUser = User::factory()->create(['role' => 'client']);
    $portalUser->roles()->attach($portalRole);
    $client = Client::factory()->create(['site_id' => null]);
    $portalUser->portalClients()->attach($client->id, ['relation' => 'self']);

    $staff = User::factory()->create(['role' => 'support_worker']);
    $staff->portalClients()->attach($client->id, ['relation' => 'staff']);

    expect($portalUser->canAccessClientPortal($client))->toBeTrue()
        ->and($portalUser->can('view', $client))->toBeTrue()
        ->and($staff->canAccessClientPortal($client))->toBeFalse()
        ->and((new User)->canAccessClientPortal($client))->toBeFalse()
        ->and($portalUser->toArray())->not->toHaveKey('organization_id')
        ->and($client->toArray())->not->toHaveKey('organization_id');
});

it('scopes assigned family communication to current care workers at the client Site', function () {
    $clientSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $clientSite->id]);
    $eligibleWorker = User::factory()->create(['role' => 'support_worker']);
    $poisonedWorker = User::factory()->create(['role' => 'support_worker']);
    foreach ([$eligibleWorker, $poisonedWorker] as $worker) {
        grantClientPolicySitePermission($worker, 'progress_notes.viewAny');
        grantClientPolicySitePermission($worker, 'progress_notes.create');
        $client->supportWorkers()->attach($worker->id);
    }
    assignClientPolicyWorkerToSite($eligibleWorker, $clientSite);
    assignClientPolicyWorkerToSite($poisonedWorker, $otherSite);

    $access = app(ClientFamilyCommunicationAccess::class);

    expect($access->canView($eligibleWorker, $client))->toBeTrue()
        ->and($access->canManage($eligibleWorker, $client))->toBeTrue()
        ->and($access->canView($poisonedWorker, $client))->toBeFalse()
        ->and($access->canManage($poisonedWorker, $client))->toBeFalse();
});

it('rejects a key worker who is not current at the selected Site during intake', function () {
    $manager = User::factory()->create(['role' => 'provider_manager']);
    grantClientPolicySitePermission($manager, 'clients.create');
    $selectedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $worker = User::factory()->create(['role' => 'support_worker']);
    assignClientPolicyWorkerToSite($worker, $otherSite);

    $this->actingAs($manager)
        ->from('/operations/clients')
        ->post('/operations/clients', [
            'site_id' => $selectedSite->id,
            'key_worker_id' => $worker->id,
            'first_name' => 'Site',
            'last_name' => 'Mismatch',
            'status' => 'onboarding',
        ])
        ->assertSessionHasErrors('key_worker_id');

    expect(Client::query()->where('first_name', 'Site')->where('last_name', 'Mismatch')->exists())
        ->toBeFalse();
});

it('revalidates the existing key worker when a clients Site changes', function () {
    $manager = User::factory()->create(['role' => 'provider_manager']);
    grantClientPolicySitePermission($manager, 'clients.update');
    $originalSite = Site::factory()->create();
    $newSite = Site::factory()->create();
    $worker = User::factory()->create(['role' => 'support_worker']);
    assignClientPolicyWorkerToSite($worker, $originalSite);
    $client = Client::factory()->create([
        'site_id' => $originalSite->id,
        'key_worker_id' => $worker->id,
        'status' => 'active',
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}/edit")
        ->put("/operations/clients/{$client->id}", [
            'site_id' => $newSite->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'status' => $client->status,
        ])
        ->assertSessionHasErrors('key_worker_id');

    expect((int) $client->fresh()->site_id)->toBe($originalSite->id);
});
