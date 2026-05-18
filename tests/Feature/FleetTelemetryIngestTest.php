<?php

namespace Tests\Feature;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FleetTelemetryIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_creates_fleet_event_and_signal(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => 'Van 1',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
        ]);

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-001',
            'imei' => 'QUE-001',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => 'QUE-001',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);

        $payload = [
            'imei' => 'QUE-001',
            'gps_time' => now()->toISOString(),
            'lat' => -41.0,
            'lng' => 174.0,
            'speed' => 12,
            'alarm' => 'sos',
        ];

        $response = $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', $payload);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertDatabaseHas('fleet_telemetry_events', [
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => 'queclink',
        ]);

        $this->assertDatabaseHas('fleet_signals', [
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'signal_type' => 'vehicle.sos',
        ]);
    }

    public function test_ingest_is_idempotent(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => 'Van 2',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
        ]);

        AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-002',
            'status' => 'paired',
            'paired_at' => now(),
        ]);

        $payload = [
            'imei' => 'QUE-002',
            'gps_time' => now()->toISOString(),
            'lat' => -41.1,
            'lng' => 174.1,
            'speed' => 0,
        ];

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', $payload);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', $payload)
            ->assertStatus(200)
            ->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('fleet_telemetry_events', 1);
    }

    public function test_geofence_signal_emitted_when_outside(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $client = Client::create(['first_name' => 'Ava', 'last_name' => 'Smith']);
        $consentType = ConsentType::create([
            'name' => 'Fleet Tracking',
            'category' => 'essential',
            'description' => 'Tracking consent',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'GDPR Art 6',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Fleet tracking v1',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'GDPR Art 6',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Van 3',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
        ]);

        AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-003',
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consent->id,
        ]);

        AssetGeofence::create([
            'asset_id' => $asset->id,
            'name' => 'Depot',
            'type' => 'circle',
            'shape' => ['lat' => 0, 'lon' => 0, 'radius_m' => 50],
            'breach_type' => 'soft',
            'is_active' => true,
        ]);

        $payload = [
            'imei' => 'QUE-003',
            'gps_time' => now()->toISOString(),
            'lat' => -41.2,
            'lng' => 174.2,
            'speed' => 0,
        ];

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', $payload)
            ->assertStatus(200);

        $this->assertDatabaseHas('fleet_signals', [
            'asset_id' => $asset->id,
            'signal_type' => 'geofence.breach',
        ]);
    }

    public function test_trip_start_and_stop(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $client = Client::create(['first_name' => 'Noah', 'last_name' => 'Lee']);
        $consentType = ConsentType::create([
            'name' => 'Fleet Tracking',
            'category' => 'essential',
            'description' => 'Tracking consent',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'GDPR Art 6',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Fleet tracking v1',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'GDPR Art 6',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Van 4',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
        ]);

        AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-004',
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consent->id,
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-004',
                'gps_time' => now()->subMinutes(10)->toISOString(),
                'lat' => -41.0,
                'lng' => 174.0,
                'speed' => 15,
            ])
            ->assertStatus(200);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-004',
                'gps_time' => now()->toISOString(),
                'lat' => -41.0,
                'lng' => 174.0,
                'speed' => 0,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('fleet_trips', [
            'asset_id' => $asset->id,
            'status' => 'closed',
        ]);
    }

    public function test_consent_masking_blocks_location_for_client_linked_tracker(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $client = Client::create(['first_name' => 'Mia', 'last_name' => 'Tane']);
        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Mia pendant',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);

        AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-005',
            'status' => 'paired',
            'paired_at' => now(),
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-005',
                'gps_time' => now()->toISOString(),
                'lat' => -41.3,
                'lng' => 174.3,
                'speed' => 5,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('fleet_telemetry_events', [
            'asset_id' => $asset->id,
            'consent_blocked' => 1,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    public function test_fleet_vehicle_without_consent_stores_coordinates(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => 'Van 6',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
        ]);

        AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-006',
            'status' => 'paired',
            'paired_at' => now(),
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-006',
                'gps_time' => now()->toISOString(),
                'lat' => -41.4,
                'lng' => 174.4,
                'speed' => 22,
            ])
            ->assertStatus(200);

        $event = FleetTelemetryEvent::where('asset_id', $asset->id)->first();
        $this->assertNotNull($event);
        $this->assertFalse((bool) $event->consent_blocked);
        $this->assertEqualsWithDelta(-41.4, (float) $event->latitude, 0.0001);
        $this->assertEqualsWithDelta(174.4, (float) $event->longitude, 0.0001);
    }

    public function test_consented_client_tracker_updates_canonical_device_location(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $client = Client::create(['first_name' => 'Amelia', 'last_name' => 'Wilson']);
        $consentType = ConsentType::create([
            'name' => 'Fleet Tracking',
            'category' => 'essential',
            'description' => 'Tracking consent',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'GDPR Art 6',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Fleet tracking v1',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'GDPR Art 6',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Amelia pendant',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-AMELIA',
            'imei' => 'QUE-AMELIA',
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consent->id,
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => 'QUE-AMELIA',
            'device_uid' => 'QUE-AMELIA',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $consent->id,
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-AMELIA',
                'gps_time' => now()->toISOString(),
                'lat' => -36.8485,
                'lng' => 174.7633,
                'speed' => 7.5,
                'course' => 180,
            ])
            ->assertStatus(200);

        $device->refresh();

        $this->assertEqualsWithDelta(-36.8485, (float) $device->latitude, 0.0001);
        $this->assertEqualsWithDelta(174.7633, (float) $device->longitude, 0.0001);
        $this->assertEquals(7.5, $device->meta['speed']);
        $this->assertEquals(180, $device->meta['heading']);
    }

    public function test_client_tracker_uses_existing_valid_client_consent_when_links_are_missing_consent_id(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Harbour Respite']);
        $client = Client::create(['first_name' => 'Amelia', 'last_name' => 'Wilson']);
        $consentType = ConsentType::create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'category' => 'safety',
            'description' => 'Personal tracker consent',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Personal tracker consent v1',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'effective_from' => now()->subDay(),
        ]);
        ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Amelia pendant',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-AMELIA-FALLBACK',
            'imei' => 'QUE-AMELIA-FALLBACK',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => 'QUE-AMELIA-FALLBACK',
            'device_uid' => 'QUE-AMELIA-FALLBACK',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-AMELIA-FALLBACK',
                'gps_time' => now()->toISOString(),
                'lat' => -37.723667,
                'lng' => 175.2416,
                'speed' => 0,
            ])
            ->assertStatus(200);

        $event = FleetTelemetryEvent::where('asset_id', $asset->id)->first();
        $this->assertNotNull($event);
        $this->assertFalse((bool) $event->consent_blocked);
        $this->assertEqualsWithDelta(-37.723667, (float) $event->latitude, 0.0001);
        $this->assertEqualsWithDelta(175.2416, (float) $event->longitude, 0.0001);

        $device->refresh();
        $this->assertEqualsWithDelta(-37.723667, (float) $device->latitude, 0.0001);
        $this->assertEqualsWithDelta(175.2416, (float) $device->longitude, 0.0001);
    }

    public function test_gl30_battery_low_updates_device_health_and_event_without_location_change(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);
        Queue::fake();

        ['device' => $device, 'asset' => $asset] = $this->createConsentedPersonalTracker('QUE-HEALTH-LOW');

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-HEALTH-LOW',
                'gps_time' => now()->toISOString(),
                'alarm' => 'battery_low',
                'event_type' => 'battery_low',
                'battery' => 15,
                'battery_low_threshold' => 20,
                'charging_status' => 'not_charging',
                'command_word' => 'GTBPL',
            ])
            ->assertStatus(200);

        $device->refresh();

        $this->assertSame(15, $device->battery_level);
        $this->assertNotNull($device->battery_updated_at);
        $this->assertSame(15, $device->meta['battery']);
        $this->assertSame(15, $device->meta['battery_level']);
        $this->assertSame('low', $device->meta['battery_status']);
        $this->assertSame('not_charging', $device->meta['charging_status']);

        $this->assertDatabaseHas('fleet_telemetry_events', [
            'asset_id' => $asset->id,
            'device_id' => $device->id,
            'event_type' => 'battery_low',
            'battery_pct' => 15,
        ]);

        $signal = FleetSignal::query()->where('signal_type', 'device.low_battery')->first();
        $this->assertNotNull($signal);
        $this->assertSame('warning', $signal->severity_hint);
        $this->assertSame(15, $signal->payload['battery_pct']);
        $this->assertSame('GTBPL', $signal->payload['command_word']);
    }

    public function test_gl30_sos_emits_resident_safety_signal_and_updates_device_meta(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);
        Queue::fake();

        ['device' => $device, 'asset' => $asset, 'tracker' => $tracker] = $this->createConsentedPersonalTracker('QUE-SOS');
        $gpsTime = now()->toISOString();

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-SOS',
                'gps_time' => $gpsTime,
                'alarm' => 'sos',
                'event_type' => 'vehicle_sos',
                'sos_flag' => true,
                'command_word' => 'GTSOS',
            ])
            ->assertStatus(200);

        $event = FleetTelemetryEvent::query()->where('asset_id', $asset->id)->first();
        $this->assertNotNull($event);

        $device->refresh();
        $this->assertSame('vehicle_sos', $device->meta['last_safety_event']);

        $this->assertDatabaseHas('fleet_signals', [
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'signal_type' => 'resident.sos',
            'severity_hint' => 'critical',
        ]);

        $signal = FleetSignal::query()->where('signal_type', 'resident.sos')->first();
        $this->assertSame($event->id, $signal->payload['event_id']);
        $this->assertSame('queclink', $signal->payload['vendor']);
        $this->assertSame('GTSOS', $signal->payload['command_word']);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-SOS',
                'gps_time' => $gpsTime,
                'alarm' => 'sos',
                'event_type' => 'vehicle_sos',
                'sos_flag' => true,
                'command_word' => 'GTSOS',
            ])
            ->assertStatus(200)
            ->assertJson(['duplicate' => true]);

        $this->assertSame(1, FleetSignal::query()->where('signal_type', 'resident.sos')->count());
    }

    public function test_gl30_charging_state_updates_device_health_without_battery_percentage_or_location(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        ['device' => $device] = $this->createConsentedPersonalTracker('QUE-HEALTH-CHARGE');

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-HEALTH-CHARGE',
                'gps_time' => now()->toISOString(),
                'event_type' => 'heartbeat',
                'external_power' => true,
                'charging_status' => 'charging',
                'power_event' => 'power_on',
            ])
            ->assertStatus(200);

        $device->refresh();

        $this->assertNull($device->battery_level);
        $this->assertSame('charging', $device->meta['charging_status']);
        $this->assertTrue($device->meta['external_power']);
        $this->assertSame('power_on', $device->meta['power_event']);
        $this->assertSame('unknown', $device->meta['battery_status']);
        $this->assertSame('Charging', $device->meta['battery_status_label']);
    }

    /**
     * @return array{client: Client, consent: ClientConsent, asset: Asset, tracker: AssetTracker, device: Device}
     */
    private function createConsentedPersonalTracker(string $deviceUid): array
    {
        $site = Site::create(['name' => 'Harbour Respite']);
        $client = Client::create(['first_name' => 'Amelia', 'last_name' => 'Wilson']);
        $consentType = ConsentType::create([
            'name' => "Personal Tracker {$deviceUid}",
            'category' => 'safety',
            'description' => 'Personal tracker consent',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Personal tracker consent v1',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
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
            'consent_id' => $consent->id,
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
            'consent_id' => $consent->id,
        ]);

        return compact('client', 'consent', 'asset', 'tracker', 'device');
    }
}
