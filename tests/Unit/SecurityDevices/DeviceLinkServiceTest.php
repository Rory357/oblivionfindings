<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeviceLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceLinkService();
    }

    public function test_link_device_to_asset(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();
        $user = User::factory()->create();

        $link = $this->service->link($device, $asset, $user->id);

        $this->assertNotNull($link->id);
        $this->assertEquals($device->id, $link->device_id);
        $this->assertEquals($asset->id, $link->asset_id);
        $this->assertEquals(LinkType::Primary, $link->link_type);
        $this->assertNull($link->unlinked_at);
    }

    public function test_link_with_installed_in_type(): void
    {
        $tracker = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create();
        $user = User::factory()->create();

        $link = $this->service->link($tracker, $vehicle, $user->id, LinkType::InstalledIn);

        $this->assertEquals(LinkType::InstalledIn, $link->link_type);
    }

    public function test_duplicate_active_link_throws(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();
        $user = User::factory()->create();

        $this->service->link($device, $asset, $user->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already actively linked');

        $this->service->link($device, $asset, $user->id);
    }

    public function test_can_relink_after_unlinking(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();
        $user = User::factory()->create();

        $link = $this->service->link($device, $asset, $user->id);
        $this->service->unlink($link);

        // Should not throw — previous link is now inactive.
        $newLink = $this->service->link($device, $asset, $user->id);

        $this->assertNotNull($newLink->id);
        $this->assertNotEquals($link->id, $newLink->id);
    }

    public function test_unlink_sets_unlinked_at(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();
        $user = User::factory()->create();

        $link = $this->service->link($device, $asset, $user->id);
        $unlinked = $this->service->unlink($link);

        $this->assertNotNull($unlinked->unlinked_at);
        $this->assertFalse($unlinked->isActive());
    }

    public function test_unlink_inactive_link_throws(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();
        $user = User::factory()->create();

        $link = $this->service->link($device, $asset, $user->id);
        $this->service->unlink($link);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already inactive');

        $this->service->unlink($link->fresh());
    }

    public function test_unlink_all_for_device(): void
    {
        $device = Device::factory()->create();
        $assetA = Asset::factory()->create();
        $assetB = Asset::factory()->create();
        $user = User::factory()->create();

        $this->service->link($device, $assetA, $user->id, LinkType::Primary);
        $this->service->link($device, $assetB, $user->id, LinkType::InstalledIn);

        $count = $this->service->unlinkAllForDevice($device);

        $this->assertEquals(2, $count);
        $this->assertCount(0, $device->activeAssetLinks);
        $this->assertCount(2, $device->assetLinks); // history preserved
    }

    public function test_multiple_devices_can_link_to_same_asset(): void
    {
        $tracker = Device::factory()->tracking()->create();
        $dashcam = Device::factory()->security()->create();
        $vehicle = Asset::factory()->vehicle()->create();
        $user = User::factory()->create();

        $link1 = $this->service->link($tracker, $vehicle, $user->id, LinkType::InstalledIn);
        $link2 = $this->service->link($dashcam, $vehicle, $user->id, LinkType::InstalledIn);

        $this->assertNotNull($link1->id);
        $this->assertNotNull($link2->id);

        $vehicleLinks = DeviceAssetLink::active()->forAsset($vehicle->id)->get();
        $this->assertCount(2, $vehicleLinks);
    }
}
