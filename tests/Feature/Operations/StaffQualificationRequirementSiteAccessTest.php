<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
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

it('keeps joined Client options and statistics within the requirement Site and service context', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = qualificationSiteManager($accessibleSite);
    $globalContext = ServiceContext::factory()->create(['site_id' => null]);
    $matchingContext = ServiceContext::factory()->create(['site_id' => $accessibleSite->id]);
    $outsideContext = ServiceContext::factory()->create(['site_id' => $outsideSite->id]);
    $client = Client::factory()->create(['site_id' => $accessibleSite->id, 'service_context_id' => $globalContext->id]);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $plain = qualificationRequirementFor($client, 'Medication support');
    $matching = qualificationRequirementFor($client, 'Clinical delegation');
    $matching->update(['service_context_id' => $matchingContext->id]);
    $global = qualificationRequirementFor($client, 'First aid');
    $global->update(['service_context_id' => $globalContext->id]);
    $mismatch = qualificationRequirementFor($client, 'Foreign context');
    $mismatch->update(['service_context_id' => $outsideContext->id]);
    qualificationRequirementFor($outsideClient, 'Outside Site');
    $visibleIds = [$plain->id, $matching->id, $global->id];
    sort($visibleIds);
    $before = StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    $this->actingAs($manager)
        ->get(route('operations.qualifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 3)
            ->where('requirements.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === $visibleIds)
            ->where('stats', ['total' => 3, 'mandatory' => 3, 'clients' => 1])
            ->has('clients', 1)
            ->where('clients.0.id', $client->id));

    $this->actingAs($manager)
        ->put(route('operations.qualifications.update', $mismatch), ['qualification_name' => 'Hidden change'])
        ->assertNotFound();
    $this->actingAs($manager)
        ->delete(route('operations.qualifications.destroy', $mismatch))
        ->assertNotFound();
    expect(StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
});

it('keeps a roster-only qualification reader out of each mutation route', function () {
    $site = Site::factory()->create();
    $reader = qualificationSiteManager($site, false);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $row = qualificationRequirementFor($client, 'Existing requirement');
    $before = StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    $this->actingAs($reader)->post(route('operations.qualifications.store'), [
        'client_id' => $client->id, 'qualification_name' => 'Reader write',
    ])->assertForbidden();
    $this->put(route('operations.qualifications.update', $row), ['qualification_name' => 'Reader write'])->assertForbidden();
    $this->delete(route('operations.qualifications.destroy', $row))->assertForbidden();
    expect(StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
});

function qualificationSiteManager(Site $site, bool $canMutate = true): User
{
    $manager = User::factory()->create(['approved_at' => now()]);
    $keys = $canMutate ? ['rostering.viewAny', 'qualifications.create', 'qualifications.edit', 'qualifications.delete'] : ['rostering.viewAny'];
    $permissions = collect($keys)->map(fn ($key) => Permission::firstOrCreate(
        ['key' => $key],
        ['description' => $key, 'group' => 'Rostering', 'module' => 'operations'],
    )->id)->all();
    $role = Role::create([
        'name' => 'qualification-site-test-'.uniqid(),
        'label' => 'Qualification Site test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->sync($permissions);
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
