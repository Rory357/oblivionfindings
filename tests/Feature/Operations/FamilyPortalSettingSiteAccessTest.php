<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('scopes family portal settings and direct client records to accessible Sites', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = familyPortalSiteManager($accessibleSite);
    $visibleClient = Client::factory()->create(['site_id' => $accessibleSite->id]);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);

    $this->actingAs($manager)
        ->get(route('operations.family_portal.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/family-portal/Index')
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $visibleClient->id));

    $this->actingAs($manager)
        ->get(route('operations.family_portal.show', $outsideClient))
        ->assertNotFound();
    $this->actingAs($manager)
        ->put(route('operations.family_portal.update', $outsideClient), [
            'show_shift_schedule' => true,
        ])
        ->assertNotFound();

    expect(FamilyPortalSetting::query()->where('client_id', $outsideClient->id)->exists())->toBeFalse();
});

function familyPortalSiteManager(Site $site): User
{
    $manager = User::factory()->create(['approved_at' => now()]);
    $permission = Permission::firstOrCreate(
        ['key' => 'clients.update'],
        ['description' => 'Update clients', 'group' => 'Clients', 'module' => 'clients'],
    );
    $role = Role::create([
        'name' => 'family-portal-site-test-'.uniqid(),
        'label' => 'Family portal Site test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->sync([$permission->id]);
    $manager->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $manager;
}
