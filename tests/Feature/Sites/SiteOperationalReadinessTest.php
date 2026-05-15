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

    $this->actingAs($user)
        ->get("/sites/{$site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->has('readiness.critical', 7)
            ->where('readiness.is_active_but_incomplete', true)
            ->where('readiness.critical_total', 7)
            ->where('occupancy.rooms_total', 2)
            ->where('occupancy.rooms_occupied', 1)
            ->where('occupancy.vacancies', 1)
            ->where('occupancy.percent', 50)
        );
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
