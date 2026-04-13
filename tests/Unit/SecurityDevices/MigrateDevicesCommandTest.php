<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrateDevicesCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────

    private function insertLocationHardware(array $overrides = []): int
    {
        $defaults = [
            'tenant_id' => 1,
            'site_id' => Site::factory()->create()->id,
            'room_id' => null,
            'provider' => 'manual',
            'category' => 'camera',
            'name' => 'Test Camera',
            'asset_tag' => null,
            'serial' => null,
            'mac' => null,
            'status' => 'online',
            'last_seen_at' => now(),
            'external_ref' => null,
            'linked_asset_id' => null,
            'linked_person_type' => null,
            'linked_person_id' => null,
            'notes' => null,
            'meta' => null,
            'device_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        return DB::table('location_hardware')->insertGetId(
            array_merge($defaults, $overrides)
        );
    }

    private function insertControlRoomDevice(array $overrides = []): int
    {
        $defaults = [
            'name' => 'CR Sensor',
            'device_uid' => 'dev-' . \Illuminate\Support\Str::uuid(),
            'type' => 'sensor',
            'vendor' => null,
            'model' => null,
            'site_id' => null,
            'location_description' => null,
            'latitude' => null,
            'longitude' => null,
            'client_id' => null,
            'asset_id' => null,
            'signal_source_id' => null,
            'external_ref' => null,
            'config' => null,
            'status' => 'online',
            'last_seen_at' => now(),
            'last_signal_at' => null,
            'battery_level' => null,
            'battery_updated_at' => null,
            'low_battery_alert_sent' => false,
            'canonical_device_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        return DB::table('control_room_devices')->insertGetId(
            array_merge($defaults, $overrides)
        );
    }

    private function insertAssetTracker(array $overrides = []): int
    {
        $defaults = [
            'asset_id' => Asset::factory()->create()->id,
            'vendor' => 'queclink',
            'device_uid' => 'TRK-' . fake()->unique()->numerify('####'),
            'imei' => null,
            'serial_number' => null,
            'status' => 'paired',
            'paired_at' => now(),
            'unpaired_at' => null,
            'last_seen_at' => null,
            'consent_id' => null,
            'vendor_metadata' => null,
            'device_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('asset_trackers')->insertGetId(
            array_merge($defaults, $overrides)
        );
    }

    // ── Phase A: location_hardware ────────────────────────────────

    public function test_migrates_location_hardware_to_device(): void
    {
        $lhId = $this->insertLocationHardware(['category' => 'camera', 'name' => 'Lobby Cam']);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_location_hardware_id', $lhId)->first();
        $this->assertNotNull($device);
        $this->assertEquals('Lobby Cam', $device->name);
        $this->assertEquals('security', $device->domain);
        $this->assertEquals('cctv', $device->category);

        // Bridge FK back-filled.
        $this->assertEquals(
            $device->id,
            DB::table('location_hardware')->where('id', $lhId)->value('device_id')
        );
    }

    public function test_maps_all_location_hardware_categories(): void
    {
        $expected = [
            'gateway' => ['it_infrastructure', 'network'],
            'switch' => ['it_infrastructure', 'network'],
            'ap' => ['it_infrastructure', 'network'],
            'camera' => ['security', 'cctv'],
            'door' => ['security', 'access_control'],
            'sensor' => ['iot_healthcare', 'environmental'],
            'nvr' => ['security', 'cctv'],
            'ai' => ['it_infrastructure', 'server'],
            'tracker' => ['tracking', 'personal_tracker'],
            'other' => ['facilities', 'building_safety'],
        ];

        foreach ($expected as $legacy => [$domain, $category]) {
            $this->insertLocationHardware([
                'category' => $legacy,
                'name' => "Device-{$legacy}",
            ]);
        }

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        foreach ($expected as $legacy => [$domain, $category]) {
            $device = Device::where('name', "Device-{$legacy}")->first();
            $this->assertNotNull($device, "No device created for legacy category '{$legacy}'");
            $this->assertEquals($domain, $device->domain, "Wrong domain for '{$legacy}'");
            $this->assertEquals($category, $device->category, "Wrong category for '{$legacy}'");
        }
    }

    public function test_creates_site_assignment_from_location_hardware(): void
    {
        $site = Site::factory()->create();
        $lhId = $this->insertLocationHardware(['site_id' => $site->id]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_location_hardware_id', $lhId)->first();
        $assignment = DeviceAssignment::where('device_id', $device->id)->active()->first();

        $this->assertNotNull($assignment);
        $this->assertEquals('site', $assignment->assignable_type);
        $this->assertEquals($site->id, $assignment->assignable_id);
    }

    public function test_creates_room_assignment_when_room_set(): void
    {
        $site = Site::factory()->create();
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);
        $lhId = $this->insertLocationHardware([
            'site_id' => $site->id,
            'room_id' => $room->id,
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_location_hardware_id', $lhId)->first();
        $assignment = DeviceAssignment::where('device_id', $device->id)->active()->first();

        $this->assertEquals('room', $assignment->assignable_type);
        $this->assertEquals($room->id, $assignment->assignable_id);
    }

    public function test_creates_person_assignment_from_location_hardware(): void
    {
        $client = Client::factory()->create();
        $lhId = $this->insertLocationHardware([
            'category' => 'tracker',
            'linked_person_type' => 'client',
            'linked_person_id' => $client->id,
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_location_hardware_id', $lhId)->first();
        $clientAssignment = DeviceAssignment::where('device_id', $device->id)
            ->where('assignable_type', 'client')
            ->first();

        $this->assertNotNull($clientAssignment);
        $this->assertEquals($client->id, $clientAssignment->assignable_id);
    }

    public function test_creates_asset_link_from_location_hardware(): void
    {
        $asset = Asset::factory()->create();
        $lhId = $this->insertLocationHardware([
            'category' => 'camera',
            'linked_asset_id' => $asset->id,
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_location_hardware_id', $lhId)->first();
        $link = DeviceAssetLink::where('device_id', $device->id)->active()->first();

        $this->assertNotNull($link);
        $this->assertEquals($asset->id, $link->asset_id);
        $this->assertEquals('primary', $link->link_type->value); // camera → primary
    }

    public function test_tracker_asset_link_uses_installed_in(): void
    {
        $asset = Asset::factory()->create();
        $lhId = $this->insertLocationHardware([
            'category' => 'tracker',
            'linked_asset_id' => $asset->id,
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $link = DeviceAssetLink::whereHas('device', fn ($q) => $q->where('legacy_location_hardware_id', $lhId))
            ->active()->first();

        $this->assertEquals('installed_in', $link->link_type->value);
    }

    public function test_extracts_fields_from_external_ref(): void
    {
        $lhId = $this->insertLocationHardware([
            'category' => 'ap',
            'external_ref' => json_encode([
                'firmware_version' => '6.5.28',
                'model' => 'U6-LR',
                'ip' => '192.168.1.42',
            ]),
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_location_hardware_id', $lhId)->first();
        $this->assertEquals('6.5.28', $device->firmware_version);
        $this->assertEquals('U6-LR', $device->model);
        $this->assertEquals('192.168.1.42', $device->ip_address);
    }

    // ── Phase B: control_room_devices ─────────────────────────────

    public function test_migrates_new_control_room_device(): void
    {
        $crId = $this->insertControlRoomDevice([
            'name' => 'Alarm Panel 1',
            'type' => 'alarm_panel',
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_control_room_device_id', $crId)->first();
        $this->assertNotNull($device);
        $this->assertEquals('Alarm Panel 1', $device->name);
        $this->assertEquals('security', $device->domain);
        $this->assertEquals('alarm', $device->category);
    }

    public function test_deduplicates_cr_device_by_vendor_external_ref(): void
    {
        // Phase A: create a location_hardware device with provider+external_ref.
        $lhId = $this->insertLocationHardware([
            'provider' => 'hikvision',
            'category' => 'camera',
            'name' => 'Front Door Camera',
            'external_ref' => json_encode(['provider_entity_id' => 'HIK-CAM-001']),
        ]);

        // Phase B: create a CR device with same vendor+external_ref.
        $crId = $this->insertControlRoomDevice([
            'name' => 'Front Door Camera',
            'type' => 'camera',
            'vendor' => 'hikvision',
            'external_ref' => 'HIK-CAM-001',
            'battery_level' => 85,
            'battery_updated_at' => now(),
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        // Should be ONE device, not two.
        $this->assertEquals(1, Device::count());

        $device = Device::first();
        $this->assertEquals($lhId, $device->legacy_location_hardware_id);
        $this->assertEquals($crId, $device->legacy_control_room_device_id);
        // Battery merged from CR.
        $this->assertEquals(85, $device->battery_level);
    }

    public function test_cr_device_creates_site_assignment(): void
    {
        $site = Site::factory()->create();
        $crId = $this->insertControlRoomDevice([
            'type' => 'sensor',
            'site_id' => $site->id,
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_control_room_device_id', $crId)->first();
        $assignment = DeviceAssignment::where('device_id', $device->id)->active()->first();

        $this->assertNotNull($assignment);
        $this->assertEquals('site', $assignment->assignable_type);
    }

    // ── Phase C: asset_trackers ───────────────────────────────────

    public function test_migrates_asset_tracker_to_device(): void
    {
        $asset = Asset::factory()->create();
        $atId = $this->insertAssetTracker([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'imei' => '123456789012345',
        ]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_asset_tracker_id', $atId)->first();
        $this->assertNotNull($device);
        $this->assertEquals('tracking', $device->domain);
        $this->assertEquals('vehicle_tracker', $device->category);
        $this->assertEquals('123456789012345', $device->imei);
    }

    public function test_deduplicates_tracker_by_imei(): void
    {
        // Phase A: location_hardware tracker with IMEI.
        $lhId = $this->insertLocationHardware([
            'category' => 'tracker',
            'name' => 'GPS Tracker 1',
            'serial' => null,
            'mac' => null,
        ]);
        // Manually set IMEI on the created device (Phase A doesn't have IMEI).
        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $deviceFromA = Device::where('legacy_location_hardware_id', $lhId)->first();
        $deviceFromA->update(['imei' => '111222333444555']);

        // Reset for Phase C re-run: rollback and re-migrate.
        // Instead, just insert a tracker with matching IMEI.
        $atId = $this->insertAssetTracker([
            'imei' => '111222333444555',
        ]);

        // Re-run migration (should merge tracker into existing device).
        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $deviceFromA->refresh();
        $this->assertEquals($atId, $deviceFromA->legacy_asset_tracker_id);
        // Only 1 device for this IMEI.
        $this->assertEquals(1, Device::where('imei', '111222333444555')->count());
    }

    public function test_tracker_creates_asset_link(): void
    {
        $asset = Asset::factory()->create();
        $atId = $this->insertAssetTracker(['asset_id' => $asset->id]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_asset_tracker_id', $atId)->first();
        $link = DeviceAssetLink::where('device_id', $device->id)->active()->first();

        $this->assertNotNull($link);
        $this->assertEquals($asset->id, $link->asset_id);
        $this->assertEquals('installed_in', $link->link_type->value);
    }

    public function test_tracker_consent_creates_client_assignment(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'status' => 'active',
            'given_at' => now(),
            'given_by_user_id' => $user->id,
            'given_method' => 'verbal',
        ]);

        $atId = $this->insertAssetTracker(['consent_id' => $consent->id]);

        $this->artisan('sd:migrate-devices')->assertSuccessful();

        $device = Device::where('legacy_asset_tracker_id', $atId)->first();
        $clientAssignment = DeviceAssignment::where('device_id', $device->id)
            ->where('assignable_type', 'client')
            ->first();

        $this->assertNotNull($clientAssignment);
        $this->assertEquals($client->id, $clientAssignment->assignable_id);
        $this->assertEquals($consent->id, $clientAssignment->consent_id);
    }

    // ── Cross-cutting ─────────────────────────────────────────────

    public function test_dry_run_writes_nothing(): void
    {
        $this->insertLocationHardware();
        $this->insertControlRoomDevice();
        $this->insertAssetTracker();

        $this->artisan('sd:migrate-devices', ['--dry-run' => true])->assertSuccessful();

        $this->assertEquals(0, Device::count());
        $this->assertEquals(0, DeviceAssignment::count());
        $this->assertEquals(0, DeviceAssetLink::count());
    }

    public function test_idempotent_second_run(): void
    {
        $this->insertLocationHardware();

        // First run.
        $this->artisan('sd:migrate-devices')->assertSuccessful();
        $firstCount = Device::count();
        $this->assertEquals(1, $firstCount);

        // Second run — should skip, not duplicate.
        $this->artisan('sd:migrate-devices')->assertSuccessful();
        $this->assertEquals(1, Device::count());
    }

    public function test_rollback_removes_migrated_devices(): void
    {
        $this->insertLocationHardware();
        $this->insertAssetTracker();

        $this->artisan('sd:migrate-devices')->assertSuccessful();
        $this->assertGreaterThan(0, Device::count());

        $this->artisan('sd:migrate-devices', ['--rollback' => true])->assertSuccessful();

        $this->assertEquals(0, Device::count());
        $this->assertEquals(0, DeviceAssignment::count());
        $this->assertEquals(0, DeviceAssetLink::count());

        // Bridge FKs cleared.
        $this->assertNull(
            DB::table('location_hardware')->whereNotNull('device_id')->first()
        );
        $this->assertNull(
            DB::table('asset_trackers')->whereNotNull('device_id')->first()
        );
    }
}
