<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VehiclePageContractTest extends TestCase
{
    use RefreshDatabase;

    private function makeFleetUser(array $permissionKeys): User
    {
        $this->seed(RbacSeeder::class);

        // Intentionally no role attachment: the admin role is synced with the
        // full permission catalog by RbacSeeder, which would mask the per-test
        // permission overrides we set below.
        $user = User::factory()->create([
            'approved_at' => now(),
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    public function test_vehicle_show_exposes_read_only_contract_for_view_only_users(): void
    {
        $user = $this->makeFleetUser(['fleet.viewAny']);
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => null,
        ]);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();

        $this->actingAs($user)
            ->get("/fleet-assets/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/vehicles/show')
                ->where('can.manage', false)
            );
    }

    public function test_vehicle_show_exposes_manage_contract_for_manage_users(): void
    {
        $user = $this->makeFleetUser(['fleet.viewAny', 'fleet.manage']);
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => null,
        ]);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create();

        $this->actingAs($user)
            ->get("/fleet-assets/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/vehicles/show')
                ->where('can.manage', true)
            );
    }

    public function test_vehicle_update_persists_accessibility_fields(): void
    {
        $user = $this->makeFleetUser(['fleet.viewAny', 'fleet.manage']);
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => null,
        ]);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create([
            'has_wheelchair_ramp' => false,
            'seating_capacity' => null,
            'accessibility_notes' => null,
        ]);

        $this->actingAs($user)
            ->put("/fleet-assets/vehicles/{$vehicle->id}", [
                'has_wheelchair_ramp' => true,
                'seating_capacity' => 9,
                'accessibility_notes' => 'QA accessibility notes',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'id' => $vehicle->id,
            'has_wheelchair_ramp' => true,
            'seating_capacity' => 9,
            'accessibility_notes' => 'QA accessibility notes',
        ]);
    }
}
