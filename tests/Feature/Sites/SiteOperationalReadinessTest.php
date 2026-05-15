<?php

use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function sitesModulePlanUser(string $roleName = 'admin'): User
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

test('sites index derives region payload and filter options from city when region is missing', function () {
    $user = sitesModulePlanUser();

    Site::factory()->create([
        'name' => 'Auckland Supported House',
        'type' => 'house',
        'city' => 'Auckland',
        'suburb' => 'Grey Lynn',
        'region' => null,
        'is_active' => true,
    ]);

    Site::factory()->create([
        'name' => 'Wellington Supported House',
        'type' => 'house',
        'city' => 'Wellington',
        'region' => null,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get('/sites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/index')
            ->where('sites.0.region', 'Auckland')
            ->where('sites.1.region', 'Wellington')
            ->where('filterOptions.regions.0', 'Auckland')
            ->where('filterOptions.regions.1', 'Wellington')
        );
});

test('site show exposes readiness and occupancy summaries for active incomplete houses', function () {
    $user = sitesModulePlanUser();
    $client = Client::factory()->create(['status' => 'active']);
    $site = Site::factory()->create([
        'name' => 'Harbour Respite',
        'type' => 'house',
        'phone' => null,
        'email' => null,
        'primary_contact_user_id' => null,
        'manager_name' => null,
        'after_hours_phone' => null,
        'emergency_plan_location' => null,
        'medication_storage_location' => null,
        'is_active' => true,
    ]);

    SiteHouseRoom::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'Bedroom 1',
        'assigned_client_id' => $client->id,
        'is_active' => true,
        'is_assignable' => true,
        'sort_order' => 1,
    ]);

    SiteHouseRoom::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'Bedroom 2',
        'is_active' => true,
        'is_assignable' => true,
        'sort_order' => 2,
    ]);

    SiteHouseRoom::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'Kitchen',
        'is_active' => true,
        'is_assignable' => false,
        'sort_order' => 3,
    ]);

    $this->actingAs($user)
        ->get("/sites/{$site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->has('readiness.critical', 7)
            ->where('readiness.is_active_but_incomplete', true)
            ->where('readiness.critical_total', 7)
            ->where('occupancy.label', 'Bedroom occupancy')
            ->where('occupancy.noun', 'bedrooms')
            ->where('occupancy.rooms_total', 2)
            ->where('occupancy.rooms_occupied', 1)
            ->where('occupancy.vacancies', 1)
            ->where('occupancy.percent', 50)
        );
});

test('sites index capacity counts assignable bedrooms only', function () {
    $user = sitesModulePlanUser();
    $site = Site::factory()->create([
        'name' => 'Kauri House',
        'type' => 'house',
        'city' => 'Auckland',
        'region' => 'Auckland',
        'is_active' => true,
    ]);

    foreach (['Bedroom 1', 'Bedroom 2', 'Bedroom 3'] as $index => $name) {
        SiteHouseRoom::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'name' => $name,
            'is_active' => true,
            'is_assignable' => true,
            'sort_order' => $index + 1,
        ]);
    }

    SiteHouseRoom::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'Kitchen',
        'is_active' => true,
        'is_assignable' => false,
        'sort_order' => 4,
    ]);

    $this->actingAs($user)
        ->get('/sites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/index')
            ->where('sites.0.name', 'Kauri House')
            ->where('sites.0.rooms_total', 3)
        );
});

test('site hazards and checklists indexes receive recommended setup lists', function () {
    $user = sitesModulePlanUser();
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get("/sites/{$site->id}/hazards")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/hazards/index')
            ->where('recommendedHazards.0.key', 'slip_trip_fall')
            ->where('recommendedHazards.0.label', 'Slip / trip hazards')
        );

    $this->actingAs($user)
        ->get("/sites/{$site->id}/checklists")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/checklists/index')
            ->where('recommendedChecklists.0.key', 'site_induction')
            ->where('recommendedChecklists.0.frequency', 'once')
        );
});

test('system catalog seeder refreshes canonical site fields on repeat runs', function () {
    Site::factory()->create([
        'name' => 'Kauri House',
        'address_line_1' => 'Old address',
        'suburb' => 'Old suburb',
        'city' => 'Wellington',
        'region' => null,
        'postcode' => '0000',
        'country' => 'New Zealand',
        'is_active' => false,
    ]);

    $this->seed(\Database\Seeders\SystemCatalogSeeder::class);

    $this->assertDatabaseHas('sites', [
        'name' => 'Kauri House',
        'address_line_1' => '12 Kauri Street',
        'suburb' => 'Grey Lynn',
        'city' => 'Auckland',
        'region' => 'Auckland',
        'postcode' => '1021',
        'country' => 'New Zealand',
        'is_active' => true,
    ]);
});

test('standard rooms endpoint adds missing defaults and is idempotent', function () {
    $user = sitesModulePlanUser();
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
    ]);

    SiteHouseRoom::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'yuikri',
        'is_active' => true,
        'is_assignable' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($user)
        ->post("/sites/{$site->id}/rooms/seed-defaults")
        ->assertRedirect();

    expect($site->houseRooms()->where('name', 'Bedroom 1')->exists())->toBeTrue();
    expect($site->houseRooms()->where('name', 'Kitchen')->exists())->toBeTrue();

    $afterFirstRun = $site->houseRooms()->count();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/rooms/seed-defaults")
        ->assertRedirect();

    expect($site->houseRooms()->count())->toBe($afterFirstRun);
});
