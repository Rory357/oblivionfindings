<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers DeviceController::linkAsset() + unlinkAsset() + DeviceLinkService.
 *
 * Routes:
 *   POST   /security-devices/devices/{device}/asset-links
 *   DELETE /security-devices/devices/{device}/asset-links/{link}
 */
class DeviceAssetLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // support_worker does NOT have devices.update.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_link_requires_devices_update_permission(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();

        $this->actingAs($this->viewer)
            ->post("/security-devices/devices/{$device->id}/asset-links", [
                'asset_id' => $asset->id,
                'link_type' => 'primary',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_link_asset_and_row_is_created(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/asset-links", [
                'asset_id' => $asset->id,
                'link_type' => 'installed_in',
                'notes' => 'Mounted in vehicle bay 3',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $link = DeviceAssetLink::query()
            ->where('device_id', $device->id)
            ->where('asset_id', $asset->id)
            ->first();

        $this->assertNotNull($link, 'A DeviceAssetLink row should exist');
        $this->assertSame('installed_in', $link->link_type?->value);
        $this->assertNotNull($link->linked_at);
        $this->assertNull($link->unlinked_at);
        $this->assertSame($this->admin->id, $link->linked_by_user_id);
    }

    public function test_duplicate_active_link_for_same_pair_is_rejected_with_session_error(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();

        // First link succeeds.
        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/asset-links", [
                'asset_id' => $asset->id,
                'link_type' => 'primary',
            ])
            ->assertRedirect();

        // Second attempt must fail with a session error (still a redirect).
        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/asset-links", [
                'asset_id' => $asset->id,
                'link_type' => 'primary',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            1,
            DeviceAssetLink::query()
                ->where('device_id', $device->id)
                ->where('asset_id', $asset->id)
                ->count(),
            'No duplicate row should have been inserted',
        );
    }

    public function test_unlink_sets_unlinked_at_without_deleting_row(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();

        $link = DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => 'primary',
            'linked_at' => now()->subDay(),
            'linked_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/security-devices/devices/{$device->id}/asset-links/{$link->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertNotNull($link->unlinked_at, 'unlinked_at should be populated');
        $this->assertDatabaseHas('device_asset_links', [
            'id' => $link->id,
        ]);
    }

    public function test_unlink_with_mismatched_device_returns_404(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        $asset = Asset::factory()->create();

        $link = DeviceAssetLink::create([
            'device_id' => $deviceA->id,
            'asset_id' => $asset->id,
            'link_type' => 'primary',
            'linked_at' => now(),
            'linked_by_user_id' => $this->admin->id,
        ]);

        // Trying to delete via deviceB's URL must 404.
        $this->actingAs($this->admin)
            ->delete("/security-devices/devices/{$deviceB->id}/asset-links/{$link->id}")
            ->assertNotFound();

        // The original link must remain active.
        $this->assertNull($link->fresh()->unlinked_at);
    }
}
