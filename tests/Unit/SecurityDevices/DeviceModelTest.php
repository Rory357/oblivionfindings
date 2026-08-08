<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Asset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_uid_is_auto_generated_on_create(): void
    {
        $device = Device::create([
            'name' => 'Test Camera',
            'domain' => 'security',
            'category' => 'cctv',
        ]);

        $this->assertNotEmpty($device->device_uid);
        $this->assertStringStartsWith('CCT-', $device->device_uid);
    }

    public function test_device_uid_is_not_overridden_if_provided(): void
    {
        $device = Device::create([
            'device_uid' => 'CUSTOM-UID-001',
            'name' => 'Test Camera',
            'domain' => 'security',
            'category' => 'cctv',
        ]);

        $this->assertEquals('CUSTOM-UID-001', $device->device_uid);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $device = Device::factory()->create();

        $this->assertInstanceOf(DeviceStatus::class, $device->status);
    }

    public function test_health_status_is_cast_to_enum(): void
    {
        $device = Device::factory()->create();

        $this->assertInstanceOf(HealthStatus::class, $device->health_status);
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $device = Device::factory()->create([
            'external_ref' => ['controller_id' => 'abc123'],
            'config' => ['poll_interval' => 60],
            'meta' => ['notes' => 'test'],
        ]);

        $device->refresh();

        $this->assertIsArray($device->external_ref);
        $this->assertEquals('abc123', $device->external_ref['controller_id']);
        $this->assertIsArray($device->config);
        $this->assertIsArray($device->meta);
    }

    public function test_soft_delete(): void
    {
        $device = Device::factory()->create();
        $device->delete();

        $this->assertSoftDeleted($device);
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_registry_query_returns_all_devices(): void
    {
        $first = Device::factory()->create([]);
        $second = Device::factory()->create([]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], Device::query()->pluck('id')->all());
    }

    public function test_by_domain_scope(): void
    {
        Device::factory()->security()->create();
        Device::factory()->itInfrastructure()->create();

        $this->assertCount(1, Device::byDomain(DeviceDomain::Security)->get());
        $this->assertCount(1, Device::byDomain('it_infrastructure')->get());
    }

    public function test_operational_scope(): void
    {
        Device::factory()->create(['status' => DeviceStatus::Active]);
        Device::factory()->create(['status' => DeviceStatus::Degraded]);
        Device::factory()->create(['status' => DeviceStatus::Offline]);
        Device::factory()->create(['status' => DeviceStatus::Decommissioned]);

        $this->assertCount(2, Device::operational()->get());
    }

    public function test_low_battery_scope(): void
    {
        Device::factory()->withBattery(80)->create();
        Device::factory()->lowBattery()->create();
        Device::factory()->create(); // no battery

        $this->assertCount(1, Device::lowBattery()->get());
    }

    public function test_needing_attention_scope(): void
    {
        Device::factory()->create(['health_status' => HealthStatus::Healthy, 'status' => DeviceStatus::Active]);
        Device::factory()->create(['health_status' => HealthStatus::Critical, 'status' => DeviceStatus::Active]);
        Device::factory()->create(['health_status' => HealthStatus::Healthy, 'status' => DeviceStatus::Offline]);

        $this->assertCount(2, Device::needingAttention()->get());
    }

    public function test_is_online_helper(): void
    {
        $active = Device::factory()->create(['status' => DeviceStatus::Active]);
        $offline = Device::factory()->create(['status' => DeviceStatus::Offline]);

        $this->assertTrue($active->isOnline());
        $this->assertFalse($offline->isOnline());
    }

    public function test_assignments_relationship(): void
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $this->assertCount(1, $device->assignments);
        $this->assertNotNull($device->activeAssignment());
    }

    public function test_asset_links_relationship(): void
    {
        $device = Device::factory()->create();
        $asset = Asset::factory()->create();

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => 'primary',
            'linked_at' => now(),
        ]);

        $this->assertCount(1, $device->assetLinks);
        $this->assertCount(1, $device->activeAssetLinks);
    }

    public function test_device_relationships(): void
    {
        $actor = User::factory()->create();
        $camera = Device::factory()->security()->create(['name' => 'Camera']);
        $nvr = Device::factory()->security()->create(['name' => 'NVR']);

        DeviceRelationship::create([
            'parent_device_id' => $nvr->id,
            'child_device_id' => $camera->id,
            'relationship_type' => 'records_to',
            'created_by_user_id' => $actor->id,
        ]);

        $this->assertCount(1, $camera->parentRelationships);
        $this->assertCount(1, $nvr->childRelationships);
    }

    public function test_groups_relationship(): void
    {
        $device = Device::factory()->create();
        $group = DeviceGroup::create([
            'name' => 'Test Group',
            'type' => 'custom',
        ]);

        $group->devices()->attach($device);

        $this->assertCount(1, $device->fresh()->groups);
        $this->assertCount(1, $group->devices);
    }

    public function test_events_relationship(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'heartbeat',
            'severity' => 'info',
            'occurred_at' => now(),
        ]);

        $this->assertCount(1, $device->events);
    }

    public function test_maintenance_records_relationship(): void
    {
        $device = Device::factory()->create();

        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'firmware_update',
            'status' => 'scheduled',
            'description' => 'Update firmware to v2.1',
            'scheduled_for' => now()->addDays(7),
        ]);

        $this->assertCount(1, $device->maintenanceRecords);
    }
}
