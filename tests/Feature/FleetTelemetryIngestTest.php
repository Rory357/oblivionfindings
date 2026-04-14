<?php

namespace Tests\Feature;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_consent_masking_blocks_location(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => 'Van 5',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'vehicle',
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
        ]);
    }
}
