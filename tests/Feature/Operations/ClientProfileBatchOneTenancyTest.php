<?php

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

function grantClientProfileBatchOneTenancyPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_batch_one_tenancy_'.$user->id],
        ['label' => 'Client profile Batch 1 tenancy', 'level' => 60, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $user->roles()->syncWithoutDetaching([$role->id]);
}

it('limits the ordinary operations client index to the viewer organisation', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileBatchOneTenancyPermissions($manager, ['clients.viewAny']);

    $sameOrganisationClient = Client::factory()->create([
        'organization_id' => 1,
        'first_name' => 'Visible',
        'last_name' => 'Client',
    ]);
    Client::factory()->create([
        'organization_id' => 2,
        'first_name' => 'Foreign',
        'last_name' => 'Client',
    ]);

    $this->actingAs($manager)
        ->get('/operations/clients')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 1)
            ->where('clients.0.id', $sameOrganisationClient->id));
});

it('limits the review queue to finalised daily notes in the viewer organisation', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileBatchOneTenancyPermissions($manager, [
        'clients.viewAny',
        'progress_notes.review',
    ]);

    $sameOrganisationSite = Site::factory()->create([
        'tenant_id' => 1,
        'name' => 'Visible House',
    ]);
    $foreignSite = Site::factory()->create([
        'tenant_id' => 2,
        'name' => 'Foreign House',
    ]);
    $sameOrganisationClient = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $sameOrganisationSite->id,
    ]);
    $foreignClient = Client::factory()->create([
        'organization_id' => 2,
        'site_id' => $foreignSite->id,
    ]);

    $visibleNote = ClientNote::query()->create([
        'organization_id' => 1,
        'client_id' => $sameOrganisationClient->id,
        'user_id' => $manager->id,
        'type' => 'daily_note',
        'subject' => 'Visible finalised daily note',
        'body' => 'This finalised daily note needs manager review.',
        'is_flagged' => true,
        'is_draft' => false,
        'occurred_at' => now(),
    ]);
    ClientNote::query()->create([
        'organization_id' => 2,
        'client_id' => $foreignClient->id,
        'user_id' => $manager->id,
        'type' => 'daily_note',
        'subject' => 'Foreign daily note',
        'body' => 'This note belongs to another organisation.',
        'is_flagged' => true,
        'is_draft' => false,
        'occurred_at' => now(),
    ]);
    ClientNote::query()->create([
        'organization_id' => 1,
        'client_id' => $sameOrganisationClient->id,
        'user_id' => $manager->id,
        'type' => 'daily_note',
        'subject' => 'Draft daily note',
        'body' => 'This draft is not ready for manager review.',
        'is_flagged' => true,
        'is_draft' => true,
        'occurred_at' => now(),
    ]);
    ClientNote::query()->create([
        'organization_id' => 1,
        'client_id' => $sameOrganisationClient->id,
        'user_id' => $manager->id,
        'type' => 'communication',
        'subject' => 'Family communication',
        'body' => 'This is not a daily note.',
        'is_flagged' => true,
        'is_draft' => false,
        'occurred_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('/operations/review-queue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operations/review-queue/index')
            ->where('stats.total', 1)
            ->where('stats.clients', 1)
            ->where('stats.sites', 1)
            ->has('items.data', 1)
            ->where('items.data.0.id', $visibleNote->id)
            ->has('sites', 1)
            ->where('sites.0.id', $sameOrganisationSite->id));
});
