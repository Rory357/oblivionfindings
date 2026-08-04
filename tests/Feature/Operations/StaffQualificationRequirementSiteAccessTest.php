<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('scopes qualification requirements and direct mutations to accessible Client Sites', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = qualificationSiteManager($accessibleSite);
    $visibleClient = Client::factory()->create(['site_id' => $accessibleSite->id]);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $visibleRequirement = qualificationRequirementFor($visibleClient, 'Medication support');
    $outsideRequirement = qualificationRequirementFor($outsideClient, 'Clinical delegation');

    $this->actingAs($manager)
        ->get(route('operations.qualifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/qualifications/Index')
            ->has('requirements.data', 1)
            ->where('requirements.data.0.id', $visibleRequirement->id));

    $this->actingAs($manager)
        ->put(route('operations.qualifications.update', $outsideRequirement), [
            'qualification_name' => 'Hidden change',
        ])
        ->assertNotFound();

    expect($outsideRequirement->fresh()->qualification_name)->toBe('Clinical delegation');
});

it('rejects creating a qualification requirement for an inaccessible Client Site', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = qualificationSiteManager($accessibleSite);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);

    $this->actingAs($manager)
        ->post(route('operations.qualifications.store'), [
            'client_id' => $outsideClient->id,
            'qualification_name' => 'Medication support',
            'qualification_type' => 'certification',
            'is_mandatory' => true,
        ])
        ->assertForbidden();

    expect(StaffQualificationRequirement::query()->where('client_id', $outsideClient->id)->exists())->toBeFalse();
});

function qualificationSiteManager(Site $site): User
{
    $manager = User::factory()->create(['approved_at' => now()]);
    $permission = Permission::firstOrCreate(
        ['key' => 'rostering.viewAny'],
        ['description' => 'View rostering', 'group' => 'Rostering', 'module' => 'operations'],
    );
    $role = Role::create([
        'name' => 'qualification-site-test-'.uniqid(),
        'label' => 'Qualification Site test',
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

function qualificationRequirementFor(Client $client, string $name): StaffQualificationRequirement
{
    return StaffQualificationRequirement::query()->create([
        'client_id' => $client->id,
        'qualification_name' => $name,
        'qualification_type' => 'certification',
        'is_mandatory' => true,
    ]);
}
