<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_view_only_user_cannot_pair_device(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $this->grantPermission($user, 'fleet.viewAny');

        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create();

        $this->actingAs($user)
            ->post('/fleet-assets/devices/pair', [
                'asset_id' => $vehicle->id,
                'device_id' => $device->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('device_asset_links', [
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
        ]);
    }

    public function test_pair_to_same_asset_is_idempotent(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create();

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post('/fleet-assets/devices/pair', [
                'asset_id' => $vehicle->id,
                'device_id' => $device->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame(
            1,
            DeviceAssetLink::where('device_id', $device->id)
                ->where('asset_id', $vehicle->id)
                ->active()
                ->count(),
        );
    }

    public function test_pairing_options_present_non_vehicle_records_as_assets(): void
    {
        $asset = Asset::factory()->create([
            'name' => 'Portable hoist',
            'asset_tag' => 'HOIST-17',
            'category' => 'Medical Device',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->get('/fleet-assets/devices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/devices/index')
                ->where('pairing_options.assets.0.id', $asset->id)
                ->where('pairing_options.assets.0.label', 'Portable hoist - HOIST-17')
            );
    }

    public function test_grant_consent_requires_linked_client(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create(['client_id' => null]);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/fleet-assets/devices/{$device->id}/consent/grant", [
                'notes' => 'No client linked',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('consent');
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'tests', 'module' => 'tests'],
        );

        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }
}
