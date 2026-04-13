<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeviceRegistryService $service;
    private int $tenantId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceRegistryService();
    }

    public function test_for_site_returns_devices_assigned_to_site(): void
    {
        $site = Site::factory()->create();
        $deviceA = Device::factory()->create(['tenant_id' => $this->tenantId]);
        $deviceB = Device::factory()->create(['tenant_id' => $this->tenantId]);

        DeviceAssignment::create([
            'device_id' => $deviceA->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forSite($this->tenantId, $site->id)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($deviceA->id, $results->first()->id);
    }

    public function test_for_site_includes_room_level_assignments(): void
    {
        $site = Site::factory()->create();
        $room = SiteRoom::create([
            'tenant_id' => $this->tenantId,
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->create(['tenant_id' => $this->tenantId]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forSite($this->tenantId, $site->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_for_site_excludes_released_assignments(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['tenant_id' => $this->tenantId]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5), // released
        ]);

        $results = $this->service->forSite($this->tenantId, $site->id)->get();

        $this->assertCount(0, $results);
    }

    public function test_for_client_returns_devices_assigned_to_client(): void
    {
        $client = Client::factory()->create();
        $device = Device::factory()->tracking()->create(['tenant_id' => $this->tenantId]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forClient($this->tenantId, $client->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_for_vehicle_returns_devices_linked_to_asset(): void
    {
        $vehicle = Asset::factory()->vehicle()->create();
        $tracker = Device::factory()->tracking()->create(['tenant_id' => $this->tenantId]);

        DeviceAssetLink::create([
            'device_id' => $tracker->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $results = $this->service->forVehicle($this->tenantId, $vehicle->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_for_staff_returns_devices_assigned_to_user(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['tenant_id' => $this->tenantId]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'staff',
            'assignable_id' => $user->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forStaff($this->tenantId, $user->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_unassigned_returns_devices_without_active_assignment(): void
    {
        $site = Site::factory()->create();
        $assigned = Device::factory()->create(['tenant_id' => $this->tenantId]);
        $unassigned = Device::factory()->create(['tenant_id' => $this->tenantId]);

        DeviceAssignment::create([
            'device_id' => $assigned->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->unassigned($this->tenantId)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($unassigned->id, $results->first()->id);
    }

    public function test_by_domain_filters_correctly(): void
    {
        Device::factory()->security()->create(['tenant_id' => $this->tenantId]);
        Device::factory()->security()->create(['tenant_id' => $this->tenantId]);
        Device::factory()->itInfrastructure()->create(['tenant_id' => $this->tenantId]);

        $this->assertCount(2, $this->service->byDomain($this->tenantId, DeviceDomain::Security)->get());
        $this->assertCount(1, $this->service->byDomain($this->tenantId, DeviceDomain::ItInfrastructure)->get());
    }

    public function test_by_category_filters_correctly(): void
    {
        Device::factory()->create(['tenant_id' => $this->tenantId, 'category' => 'cctv']);
        Device::factory()->create(['tenant_id' => $this->tenantId, 'category' => 'alarm']);

        $this->assertCount(1, $this->service->byCategory($this->tenantId, 'cctv')->get());
    }

    public function test_for_group_returns_group_members(): void
    {
        $group = DeviceGroup::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Auckland Office',
            'type' => 'location',
        ]);

        $member = Device::factory()->create(['tenant_id' => $this->tenantId]);
        $nonMember = Device::factory()->create(['tenant_id' => $this->tenantId]);

        $group->devices()->attach($member);

        $results = $this->service->forGroup($this->tenantId, $group->id)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($member->id, $results->first()->id);
    }

    public function test_tenant_isolation(): void
    {
        Device::factory()->create(['tenant_id' => 1]);
        Device::factory()->create(['tenant_id' => 2]);

        $this->assertCount(1, $this->service->query(1)->get());
        $this->assertCount(1, $this->service->query(2)->get());
    }
}
