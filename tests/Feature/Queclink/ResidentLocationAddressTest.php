<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\ReverseGeocodeFleetTelemetryEvent;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\FleetTelemetryEvent;
use App\Models\Site;
use App\Services\Fleet\ReverseGeocodeService;
use App\Services\Integration\IntegrationEventHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentLocationAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_uses_address_first_and_keeps_coordinates(): void
    {
        ['device' => $device] = $this->createResidentTracker('QUE-ADDR-1');

        $this->createTelemetryEvent($device, [
            'address' => '12 Queen Street, Hamilton',
            'latitude' => -37.7870000,
            'longitude' => 175.2793000,
        ]);

        $locations = app(IntegrationEventHistoryService::class)->forDevice($device);

        $this->assertCount(1, $locations);
        $this->assertSame('12 Queen Street, Hamilton', $locations[0]['address']);
        $this->assertSame('-37.787000, 175.279300', $locations[0]['coordinates']);
        $this->assertSame('12 Queen Street, Hamilton', $locations[0]['display_location']);
    }

    public function test_history_falls_back_to_coordinates_when_address_is_missing(): void
    {
        ['device' => $device] = $this->createResidentTracker('QUE-ADDR-2');

        $this->createTelemetryEvent($device, [
            'address' => null,
            'latitude' => -41.2866000,
            'longitude' => 174.7756000,
        ]);

        $locations = app(IntegrationEventHistoryService::class)->forDevice($device);

        $this->assertCount(1, $locations);
        $this->assertNull($locations[0]['address']);
        $this->assertSame('-41.286600, 174.775600', $locations[0]['coordinates']);
        $this->assertSame('-41.286600, 174.775600', $locations[0]['display_location']);
    }

    public function test_consent_blocked_events_do_not_return_address_or_coordinates(): void
    {
        ['device' => $device] = $this->createResidentTracker('QUE-ADDR-3');

        $this->createTelemetryEvent($device, [
            'address' => 'Hidden location',
            'latitude' => -36.8485000,
            'longitude' => 174.7633000,
            'consent_blocked' => true,
        ]);

        $locations = app(IntegrationEventHistoryService::class)->forDevice($device);

        $this->assertCount(0, $locations);
    }

    public function test_reverse_geocoding_disabled_does_not_queue_lookup_from_ingest(): void
    {
        config([
            'services.telemetry.ingest_token' => 'test-token',
            'fleet.maps.reverse_geocode_enabled' => false,
        ]);
        Queue::fake();

        ['asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createVehicleTracker('QUE-ADDR-4');

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-ADDR-4',
                'gps_time' => now()->toISOString(),
                'lat' => -36.8485,
                'lng' => 174.7633,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Queue::assertNotPushed(ReverseGeocodeFleetTelemetryEvent::class);
        $this->assertDatabaseHas('fleet_telemetry_events', [
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
        ]);
    }

    public function test_reverse_geocoding_enabled_queues_lookup_from_ingest(): void
    {
        config([
            'services.telemetry.ingest_token' => 'test-token',
            'fleet.maps.reverse_geocode_enabled' => true,
        ]);
        Queue::fake();

        $this->createVehicleTracker('QUE-ADDR-5');

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-ADDR-5',
                'gps_time' => now()->toISOString(),
                'lat' => -36.8485,
                'lng' => 174.7633,
            ])
            ->assertOk();

        Queue::assertPushed(ReverseGeocodeFleetTelemetryEvent::class);
    }

    public function test_reverse_geocode_job_stores_address_or_failure_without_throwing(): void
    {
        config(['fleet.maps.reverse_geocode_enabled' => true]);

        ['device' => $device] = $this->createResidentTracker('QUE-ADDR-6');
        $event = $this->createTelemetryEvent($device, [
            'latitude' => -37.7870000,
            'longitude' => 175.2793000,
        ]);

        $successGeocoder = new class extends ReverseGeocodeService
        {
            public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string
            {
                return '12 Queen Street, Hamilton';
            }
        };

        (new ReverseGeocodeFleetTelemetryEvent($event->id))->handle($successGeocoder);

        $event->refresh();
        $this->assertSame('12 Queen Street, Hamilton', $event->address);
        $this->assertNotNull($event->reverse_geocoded_at);
        $this->assertNull($event->reverse_geocode_failed_at);

        $failureEvent = $this->createTelemetryEvent($device, [
            'latitude' => -37.7880000,
            'longitude' => 175.2803000,
        ]);
        $failureGeocoder = new class extends ReverseGeocodeService
        {
            public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string
            {
                return null;
            }
        };

        (new ReverseGeocodeFleetTelemetryEvent($failureEvent->id))->handle($failureGeocoder);

        $failureEvent->refresh();
        $this->assertNull($failureEvent->address);
        $this->assertNotNull($failureEvent->reverse_geocode_failed_at);
    }

    private function createResidentTracker(string $deviceUid): array
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'status' => 'active',
            'site_id' => $site->id,
        ]);
        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => "{$client->first_name} pendant",
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => $deviceUid,
            'imei' => $deviceUid,
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => $deviceUid,
            'device_uid' => $deviceUid,
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        return compact('site', 'client', 'asset', 'tracker', 'device');
    }

    private function createVehicleTracker(string $deviceUid): array
    {
        $site = Site::factory()->create();
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => 'Fleet van',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => $deviceUid,
            'imei' => $deviceUid,
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => $deviceUid,
            'device_uid' => $deviceUid,
            'legacy_asset_tracker_id' => $tracker->id,
        ]);

        return compact('site', 'asset', 'tracker', 'device');
    }

    private function createTelemetryEvent(Device $device, array $overrides = []): FleetTelemetryEvent
    {
        $tracker = AssetTracker::query()->where('device_uid', $device->device_uid)->firstOrFail();

        return FleetTelemetryEvent::create(array_merge([
            'asset_id' => $tracker->asset_id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => 'queclink',
            'vendor_message_id' => null,
            'occurred_at' => now(),
            'received_at' => now(),
            'latitude' => -36.8485000,
            'longitude' => 174.7633000,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'raw_payload' => [],
            'consent_blocked' => false,
        ], $overrides));
    }
}
