<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetBoundedOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $permissions): User
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['approved_at' => now()]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'fleet'],
            );
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    public function test_work_order_option_search_is_bounded_and_manage_only(): void
    {
        $viewer = $this->makeUser(['fleet.viewAny']);
        $manager = $this->makeUser(['fleet.viewAny', 'fleet.maintenance.manage']);
        Asset::factory()->count(25)->create(['name' => 'Searchable work asset']);

        $this->actingAs($viewer)
            ->getJson('/fleet-assets/maintenance/work-orders/options/search?type=assets&q=Searchable')
            ->assertForbidden();

        $this->actingAs($manager)
            ->getJson('/fleet-assets/maintenance/work-orders/options/search?type=assets&q=a')
            ->assertUnprocessable();

        $this->actingAs($manager)
            ->getJson('/fleet-assets/maintenance/work-orders/options/search?type=assets&q=Searchable')
            ->assertOk()
            ->assertJsonCount(20, 'results');
    }

    public function test_incident_option_search_preserves_view_permission_and_caps_results(): void
    {
        $viewer = $this->makeUser(['fleet.viewAny']);
        $forbidden = User::factory()->create(['approved_at' => now()]);
        User::factory()->count(25)->create(['name' => 'Searchable incident driver']);

        $this->actingAs($forbidden)
            ->getJson('/fleet-assets/incidents/options/search?type=users&q=Searchable')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->getJson('/fleet-assets/incidents/options/search?type=users&q=x')
            ->assertUnprocessable();

        $this->actingAs($viewer)
            ->getJson('/fleet-assets/incidents/options/search?type=users&q=Searchable')
            ->assertOk()
            ->assertJsonCount(20, 'results');
    }

    public function test_device_pairing_option_search_is_bounded_and_manage_only(): void
    {
        $viewer = $this->makeUser(['fleet.viewAny']);
        $manager = $this->makeUser(['fleet.viewAny', 'fleet.manage']);
        Device::factory()->tracking()->count(25)->create(['provider' => 'Searchable Provider']);

        $this->actingAs($viewer)
            ->getJson('/fleet-assets/devices/options/search?type=devices&q=Searchable')
            ->assertForbidden();

        $this->actingAs($manager)
            ->getJson('/fleet-assets/devices/options/search?type=devices&q=s')
            ->assertUnprocessable();

        $this->actingAs($manager)
            ->getJson('/fleet-assets/devices/options/search?type=devices&q=Searchable')
            ->assertOk()
            ->assertJsonCount(20, 'results');
    }

    public function test_initial_option_payloads_are_small_and_keep_selected_values(): void
    {
        $manager = $this->makeUser([
            'fleet.viewAny',
            'fleet.manage',
            'fleet.maintenance.manage',
        ]);
        Asset::factory()->count(25)->create(['name' => 'AAA Initial asset']);
        $selected = Asset::factory()->create(['name' => 'ZZZ Selected asset']);

        $workOrderResponse = $this->actingAs($manager)
            ->get("/fleet-assets/maintenance/work-orders?new=1&asset_id={$selected->id}")
            ->assertOk();
        $workOrderAssets = collect($workOrderResponse->inertiaProps('assets'));
        $this->assertLessThanOrEqual(21, $workOrderAssets->count());
        $this->assertTrue($workOrderAssets->contains('id', $selected->id));

        $incidentResponse = $this->actingAs($manager)
            ->get("/fleet-assets/incidents?vehicle_id={$selected->id}")
            ->assertOk();
        $incidentAssets = collect($incidentResponse->inertiaProps('formOptions.assets'));
        $this->assertLessThanOrEqual(21, $incidentAssets->count());
        $this->assertTrue($incidentAssets->contains('id', $selected->id));

        $deviceResponse = $this->actingAs($manager)
            ->get('/fleet-assets/devices')
            ->assertOk();
        $this->assertLessThanOrEqual(
            20,
            collect($deviceResponse->inertiaProps('pairing_options.assets'))->count(),
        );
    }

    public function test_fleet_exports_do_not_buffer_five_thousand_models(): void
    {
        $controllers = [
            'AssetController.php',
            'DriverController.php',
            'IncidentController.php',
            'MileageController.php',
            'ResidentTransportController.php',
            'VehicleBookingController.php',
            'VehicleController.php',
            'WorkOrderController.php',
        ];

        foreach ($controllers as $controller) {
            $source = file_get_contents(app_path("Http/Controllers/FleetAssets/{$controller}"));
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression(
                '/limit\(5000\).*?get\(\)/s',
                $source,
                "{$controller} still buffers its export in memory.",
            );
        }
    }
}
