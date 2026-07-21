<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DeviceRegistryService::class);
    }

    public function test_for_site_returns_devices_assigned_to_site(): void
    {
        $site = Site::factory()->create();
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();

        DeviceAssignment::create([
            'device_id' => $deviceA->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forSite($site->id)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($deviceA->id, $results->first()->id);
    }

    public function test_for_site_includes_room_level_assignments(): void
    {
        $site = Site::factory()->create();
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->create();
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forSite($site->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_for_site_excludes_released_assignments(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create();

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5), // released
        ]);

        $results = $this->service->forSite($site->id)->get();

        $this->assertCount(0, $results);
    }

    public function test_for_client_returns_devices_assigned_to_client(): void
    {
        $client = Client::factory()->create();
        $device = Device::factory()->tracking()->create();

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forClient($client->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_for_vehicle_returns_devices_linked_to_asset(): void
    {
        $vehicle = Asset::factory()->vehicle()->create();
        $tracker = Device::factory()->tracking()->create();

        DeviceAssetLink::create([
            'device_id' => $tracker->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $results = $this->service->forVehicle($vehicle->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_for_staff_returns_devices_assigned_to_user(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create();

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'staff',
            'assignable_id' => $user->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->forStaff($user->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_unassigned_returns_devices_without_active_assignment(): void
    {
        $site = Site::factory()->create();
        $assigned = Device::factory()->create();
        $unassigned = Device::factory()->create();

        DeviceAssignment::create([
            'device_id' => $assigned->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $results = $this->service->unassigned()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($unassigned->id, $results->first()->id);
    }

    public function test_by_domain_filters_correctly(): void
    {
        Device::factory()->security()->create();
        Device::factory()->security()->create();
        Device::factory()->itInfrastructure()->create();

        $this->assertCount(2, $this->service->byDomain(DeviceDomain::Security)->get());
        $this->assertCount(1, $this->service->byDomain(DeviceDomain::ItInfrastructure)->get());
    }

    public function test_by_category_filters_correctly(): void
    {
        Device::factory()->create(['category' => 'cctv']);
        Device::factory()->create(['category' => 'alarm']);

        $this->assertCount(1, $this->service->byCategory('cctv')->get());
    }

    public function test_for_group_returns_group_members(): void
    {
        $group = DeviceGroup::create([
            'tenant_id' => 1,
            'name' => 'Auckland Office',
            'type' => 'location',
        ]);

        $member = Device::factory()->create();
        $nonMember = Device::factory()->create();

        $group->devices()->attach($member);

        $results = $this->service->forGroup($group->id)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($member->id, $results->first()->id);
    }

    public function test_legacy_partition_values_do_not_split_the_application_registry(): void
    {
        Device::factory()->create(['tenant_id' => 1]);
        Device::factory()->create(['tenant_id' => 2]);

        $this->assertCount(2, $this->service->query()->get());
    }
}
