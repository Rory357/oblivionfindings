<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetDeviceRuntimeService;
use App\Services\Fleet\FleetSignalService;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
            'device_uid' => 'QUE-001',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        $this->linkCanonicalDevice($device, $asset);

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

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-002',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $this->createCanonicalDevice('QUE-002', $asset, $tracker);

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
            'legal_basis' => 'Privacy Act 2020 IPP basis',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Fleet tracking v1',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'Privacy Act 2020 IPP basis',
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

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-003',
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consent->id,
        ]);
        $this->createCanonicalDevice('QUE-003', $asset, $tracker);

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
            'legal_basis' => 'Privacy Act 2020 IPP basis',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Fleet tracking v1',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'Privacy Act 2020 IPP basis',
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

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-004',
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consent->id,
        ]);
        $this->createCanonicalDevice('QUE-004', $asset, $tracker);

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

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-005',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $this->createCanonicalDevice('QUE-005', $asset, $tracker);

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

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-006',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $this->createCanonicalDevice('QUE-006', $asset, $tracker);

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
        $client = Client::create([
            'site_id' => $site->id,
            'first_name' => 'Amelia',
            'last_name' => 'Wilson',
        ]);
        $consentType = ConsentType::create([
            'name' => 'Fleet Tracking',
            'category' => 'essential',
            'description' => 'Tracking consent',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'Privacy Act 2020 IPP basis',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Fleet tracking v1',
            'purpose' => 'Fleet tracking',
            'legal_basis' => 'Privacy Act 2020 IPP basis',
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
        $this->linkCanonicalDevice($device, $asset);
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

    public function test_client_tracker_fails_closed_when_assignment_and_tracker_lack_consent_id(): void
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
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);
        $this->assertTrue($consent->isValid());

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
        $this->linkCanonicalDevice($device, $asset);
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
        $this->assertTrue((bool) $event->consent_blocked);
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);

        $device->refresh();
        $this->assertNull($device->latitude);
        $this->assertNull($device->longitude);
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

    public function test_staff_tracker_sos_routes_to_lone_worker_emergency_not_resident(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 617;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['device' => $device, 'asset' => $asset, 'site' => $site] = $this->createStaffTracker(
            'QUE-STAFF-SOS',
            $worker->id,
            $tenantId,
        );
        $session = $this->createLiveLoneWorkerSession($worker, $site);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-STAFF-SOS',
                'gps_time' => now()->toISOString(),
                'alarm' => 'sos',
                'event_type' => 'man_down',
                'sos_flag' => true,
                'lat' => -41.5,
                'lng' => 174.5,
                'command_word' => 'GTMAN',
            ])
            ->assertStatus(200);

        // The worker's live session flips to emergency...
        $this->assertSame('emergency', $session->fresh()->status);
        // ...a lone-worker Control Room alert is raised...
        $this->assertTrue(
            ControlRoomAlert::where('source', 'lone_worker')->exists(),
            'Expected a lone_worker Control Room alert from the staff tracker SOS.',
        );
        $this->assertTrue(
            Signal::query()
                ->where('signal_type_code', LoneWorkerSignalService::TYPE_EMERGENCY)
                ->where('site_id', $site->id)
                ->exists(),
            'Expected the canonical same-Site lone-worker emergency signal.',
        );
        $this->assertDatabaseHas('fleet_signals', [
            'asset_id' => $asset->id,
            'device_id' => $device->id,
            'signal_type' => 'vehicle.sos',
        ]);
        $this->assertDatabaseMissing('lone_worker_alerts', [
            'lone_worker_session_id' => $session->id,
        ]);
        // ...and it is NOT mislabelled as a resident SOS (the guard).
        $this->assertDatabaseMissing('fleet_signals', ['signal_type' => 'resident.sos']);
    }

    public function test_sos_person_routing_rejects_a_staff_assignment_from_another_site(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $deviceTenantId = 619;
        $workerTenantId = 620;
        $foreignWorker = User::factory()->create(['organization_id' => $workerTenantId]);
        ['asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            'QUE-STAFF-CROSS-TENANT',
            $foreignWorker->id,
            $deviceTenantId,
        );
        $foreignSite = Site::create([
            'tenant_id' => $workerTenantId,
            'name' => 'Foreign Field Base',
        ]);
        $foreignSession = $this->createLiveLoneWorkerSession($foreignWorker, $foreignSite);

        $this->postStaffSos('QUE-STAFF-CROSS-TENANT');

        $this->assertSosRemainsDeviceOnly(
            $asset,
            $tracker,
            $device->id,
            $foreignWorker,
            $foreignSession,
        );
    }

    public function test_sos_person_routing_revalidates_assignment_released_and_reassigned_after_device_resolution(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $deviceTenantId = 621;
        $foreignTenantId = 622;
        $localWorker = User::factory()->create(['organization_id' => $deviceTenantId]);
        $foreignWorker = User::factory()->create(['organization_id' => $foreignTenantId]);
        ['site' => $localSite, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            'QUE-STAFF-REASSIGNED',
            $localWorker->id,
            $deviceTenantId,
        );
        $localSession = $this->createLiveLoneWorkerSession($localWorker, $localSite);
        $foreignSite = Site::create([
            'tenant_id' => $foreignTenantId,
            'name' => 'Reassigned Foreign Base',
        ]);
        $foreignSession = $this->createLiveLoneWorkerSession($foreignWorker, $foreignSite);

        $this->mutateAfterInitialDeviceResolution($device, function () use ($device, $foreignWorker): void {
            DeviceAssignment::query()
                ->where('device_id', $device->id)
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
                ->update(['released_at' => now()]);
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_STAFF,
                'assignable_id' => $foreignWorker->id,
                'assigned_at' => now(),
            ]);
        });

        $this->postStaffSos('QUE-STAFF-REASSIGNED');

        $this->assertSame('active', $localSession->fresh()->status);
        $this->assertSosRemainsDeviceOnly(
            $asset,
            $tracker,
            $device->id,
            $foreignWorker,
            $foreignSession,
        );
    }

    public function test_sos_person_routing_rejects_a_primary_driver_from_another_site(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $deviceTenantId = 623;
        $foreignTenantId = 624;
        $originalWorker = User::factory()->create(['organization_id' => $deviceTenantId]);
        $foreignWorker = User::factory()->create(['organization_id' => $foreignTenantId]);
        ['asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            'QUE-STAFF-DRIVER-FALLBACK',
            $originalWorker->id,
            $deviceTenantId,
        );
        DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->update(['released_at' => now()]);
        $asset->update(['primary_driver_user_id' => $foreignWorker->id]);
        $foreignSite = Site::create([
            'tenant_id' => $foreignTenantId,
            'name' => 'Foreign Driver Base',
        ]);
        $foreignSession = $this->createLiveLoneWorkerSession($foreignWorker, $foreignSite);

        $this->postStaffSos('QUE-STAFF-DRIVER-FALLBACK');

        $this->assertSosRemainsDeviceOnly(
            $asset,
            $tracker,
            $device->id,
            $foreignWorker,
            $foreignSession,
        );
    }

    #[DataProvider('invalidLockedDeviceMutationProvider')]
    public function test_sos_person_routing_rejects_device_changed_before_locked_revalidation(
        string $mutation,
    ): void {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 625;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        $deviceUid = 'QUE-STAFF-DEVICE-'.strtoupper(str_replace('_', '-', $mutation));
        ['site' => $site, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            $deviceUid,
            $worker->id,
            $tenantId,
        );
        $session = $this->createLiveLoneWorkerSession($worker, $site);

        $this->mutateAfterInitialDeviceResolution($device, function () use ($device, $mutation): void {
            $changes = match ($mutation) {
                'domain' => ['domain' => 'access_control'],
                'provider' => ['provider' => 'teltonika'],
                default => throw new RuntimeException("Unknown device mutation: {$mutation}"),
            };

            Device::query()->whereKey($device->id)->update($changes);
        });

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => $deviceUid,
                'gps_time' => now()->toISOString(),
                'alarm' => 'sos',
                'event_type' => 'man_down',
                'sos_flag' => true,
                'lat' => -41.5,
                'lng' => 174.5,
                'command_word' => 'GTMAN',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Canonical telemetry binding changed; retry with the current pairing.');

        $this->assertSame('active', $session->fresh()->status);
        $this->assertDatabaseMissing('fleet_telemetry_events', ['asset_id' => $asset->id]);
        $this->assertDatabaseMissing('fleet_signals', ['asset_id' => $asset->id]);
    }

    public static function invalidLockedDeviceMutationProvider(): array
    {
        return [
            'device domain changed' => ['domain'],
            'device provider changed' => ['provider'],
        ];
    }

    public function test_final_sos_acceptance_treats_released_staff_history_as_person_intent(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 627;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            'QUE-STAFF-RELEASED-HISTORY',
            $worker->id,
            $tenantId,
        );
        $session = $this->createLiveLoneWorkerSession($worker, $site);
        DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->update(['released_at' => now()]);

        $this->postStaffSos('QUE-STAFF-RELEASED-HISTORY');

        $this->assertSosRemainsDeviceOnly($asset, $tracker, $device->id, $worker, $session);
    }

    public function test_final_telemetry_acceptance_released_staff_history_suppresses_same_site_primary_driver_fallback(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 633;
        $historicalWorker = User::factory()->create(['organization_id' => $tenantId]);
        $fallbackWorker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            'QUE-STAFF-RELEASED-NO-FALLBACK',
            $historicalWorker->id,
            $tenantId,
        );
        DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->update(['released_at' => now()]);
        $asset->update(['primary_driver_user_id' => $fallbackWorker->id]);
        $session = $this->createLiveLoneWorkerSession($fallbackWorker, $site);

        $this->postStaffSos('QUE-STAFF-RELEASED-NO-FALLBACK');

        $this->assertSosRemainsDeviceOnly($asset, $tracker, $device->id, $fallbackWorker, $session);
        $event = FleetTelemetryEvent::query()->where('asset_id', $asset->id)->sole();
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
        $this->assertNull($event->speed_kph);
        $this->assertNull($event->heading_deg);
    }

    #[DataProvider('invalidSensitivePersonAttributionProvider')]
    public function test_final_telemetry_acceptance_invalid_person_attribution_sanitizes_every_persisted_surface(
        string $mutation,
    ): void {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 634;
        $deviceUid = 'QUE-SENSITIVE-'.strtoupper(str_replace('_', '-', $mutation));
        ['site' => $site, 'client' => $client, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedPersonalTracker($deviceUid, $tenantId);
        $otherSite = Site::create(['name' => 'Other sensitive attribution Site']);

        if ($mutation === 'active_assignment_mismatch') {
            $otherClient = Client::factory()->create([
                'organization_id' => $tenantId,
                'site_id' => $site->id,
            ]);
            DeviceAssignment::query()
                ->where('device_id', $device->id)
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->update(['released_at' => now()]);
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_CLIENT,
                'assignable_id' => $otherClient->id,
                'assigned_at' => now(),
            ]);
        } else {
            $this->mutateAfterInitialDeviceResolution($device, function () use (
                $client,
                $otherSite,
                $mutation,
            ): void {
                match ($mutation) {
                    'client_site_contradiction' => Client::query()->whereKey($client->id)->update(['site_id' => $otherSite->id]),
                    default => throw new RuntimeException("Unknown sensitive attribution mutation: {$mutation}"),
                };
            });
        }

        $payload = [
            'imei' => $deviceUid,
            'gps_time' => now()->toISOString(),
            'alarm' => 'sos',
            'event_type' => 'vehicle_sos',
            'sos_flag' => true,
            'client_id' => $client->id,
            'resident_id' => 910001,
            'worker_id' => 910002,
            'worker_user_id' => 910003,
            'lone_worker_session_id' => 910004,
            'session_id' => 910005,
            'user_id' => 910006,
            'lat' => -41.7654321,
            'lng' => 174.1234567,
            'speed' => 47.25,
            'heading' => 83,
            'accuracy' => 4,
            'altitude' => 19,
            'command_word' => 'GTSOS',
            'context' => [
                'safe_fleet_note' => 'retain-operational-envelope',
                'client_id' => $client->id,
                'person' => [
                    'resident_id' => 910001,
                    'user_id' => 910006,
                    'location' => [
                        'latitude' => -41.7654321,
                        'longitude' => 174.1234567,
                    ],
                ],
                'worker' => [
                    'worker_user_id' => 910003,
                    'session_id' => 910005,
                ],
            ],
            'metadata' => [
                'safe_device_state' => 'panic',
                'resident' => ['id' => 910001],
                'gps' => [
                    'lat' => -41.7654321,
                    'lng' => 174.1234567,
                    'speed_kph' => 47.25,
                    'heading_deg' => 83,
                    'accuracy_m' => 4,
                ],
            ],
        ];

        // Non-vacuous guard: the request itself really contains every class of
        // person identifier and location value that the fail-closed path must strip.
        $this->assertSame($client->id, $payload['client_id']);
        $this->assertSame(910001, $payload['resident_id']);
        $this->assertSame(910003, data_get($payload, 'context.worker.worker_user_id'));
        $this->assertSame(910005, data_get($payload, 'context.worker.session_id'));
        $this->assertSame(910006, data_get($payload, 'context.person.user_id'));
        $this->assertSame(-41.7654321, data_get($payload, 'context.person.location.latitude'));
        $this->assertSame(174.1234567, data_get($payload, 'metadata.gps.lng'));
        $this->assertSame(47.25, $payload['speed']);
        $this->assertSame(83, $payload['heading']);
        $this->assertSame(4, $payload['accuracy']);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', $payload)
            ->assertOk();

        $event = FleetTelemetryEvent::query()->where('asset_id', $asset->id)->sole();
        $snapshot = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->latest('occurred_at')
            ->sole();
        $state = FleetVehicleStateSnapshot::query()->findOrFail($asset->id);
        $vehicleSignal = FleetSignal::query()
            ->where('asset_id', $asset->id)
            ->where('signal_type', 'vehicle.sos')
            ->sole();

        $this->assertNull($event->device_id);
        $this->assertNull($snapshot->device_id);
        $this->assertNull($vehicleSignal->device_id);
        foreach (['latitude', 'longitude', 'accuracy_m', 'speed_kph', 'heading_deg', 'altitude_m'] as $field) {
            $this->assertNull($event->{$field}, "Telemetry event {$field} must be fail-closed.");
        }
        foreach (['latitude', 'longitude', 'accuracy_m', 'speed_kph'] as $field) {
            $this->assertNull($snapshot->{$field}, "Telemetry snapshot {$field} must be fail-closed.");
        }
        foreach (['latitude', 'longitude', 'speed_kph', 'heading_deg'] as $field) {
            $this->assertNull($state->{$field}, "Vehicle state {$field} must be fail-closed.");
        }

        $this->assertSame('QUE-SENSITIVE-'.strtoupper(str_replace('_', '-', $mutation)), $event->raw_payload['imei'] ?? null);
        $this->assertSame('GTSOS', $event->raw_payload['command_word'] ?? null);
        $this->assertArrayNotHasKey('context', $event->raw_payload);
        $this->assertArrayNotHasKey('context', $snapshot->vendor_metadata);
        foreach ([$event->raw_payload, $snapshot->vendor_metadata, $vehicleSignal->payload] as $persistedPayload) {
            $this->assertSensitivePersonAndLocationPayloadRemoved($persistedPayload ?? []);
        }

        $this->assertSame(['vehicle.sos'], FleetSignal::query()->pluck('signal_type')->all());
        $this->assertDatabaseMissing('fleet_signals', ['signal_type' => 'resident.sos']);
        $this->assertDatabaseMissing('fleet_signals', ['signal_type' => 'lone_worker.sos']);
        $this->assertFalse(
            Signal::query()->where('signal_type_code', LoneWorkerSignalService::TYPE_EMERGENCY)->exists(),
        );
        $this->assertFalse(ControlRoomAlert::query()->where('source', 'lone_worker')->exists());
        ControlRoomAlert::query()->get()->each(function (ControlRoomAlert $alert): void {
            $this->assertNull($alert->client_id);
            $this->assertNull($alert->device_id);
            $this->assertSensitivePersonAndLocationPayloadRemoved($alert->context ?? []);
        });
    }

    public static function invalidSensitivePersonAttributionProvider(): array
    {
        return [
            'active client assignment contradicts the asset client' => ['active_assignment_mismatch'],
            'client and asset Sites contradict' => ['client_site_contradiction'],
        ];
    }

    public function test_task7_final_telemetry_consent_blocked_payload_uses_a_safe_operational_envelope(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 638;
        $site = Site::create([
            'tenant_id' => $tenantId,
            'name' => 'Privacy Test Site',
        ]);
        $client = Client::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
        ]);
        $asset = Asset::create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Consent-blocked pendant',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-SAFE-ENVELOPE',
            'imei' => 'QUE-SAFE-ENVELOPE',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'tenant_id' => $tenantId,
            'provider' => 'queclink',
            'imei' => 'QUE-SAFE-ENVELOPE',
            'device_uid' => 'QUE-SAFE-ENVELOPE',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        $this->linkCanonicalDevice($device, $asset);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-SAFE-ENVELOPE',
                'gps_time' => now()->toISOString(),
                'alarm' => 'heartbeat',
                'event_type' => 'heartbeat',
                'command_word' => 'GTFRI',
                'battery' => 81,
                'workerUserId' => 770001,
                'resident-id' => 770002,
                'clientId' => $client->id,
                'last-location-at' => now()->subMinute()->toISOString(),
                'context' => [
                    'personLocation' => ['lat' => -41.91, 'lng' => 174.91],
                    'safe_fleet_note' => 'must-not-survive-an-untrusted-envelope',
                ],
                'structured' => json_encode([
                    'workerUserId' => 770001,
                    'resident-id' => 770002,
                    'gps' => ['latitude' => -41.91, 'longitude' => 174.91],
                ]),
            ])
            ->assertOk();

        $event = FleetTelemetryEvent::query()->where('asset_id', $asset->id)->sole();
        $snapshot = AssetTelemetrySnapshot::query()->where('asset_id', $asset->id)->sole();

        $this->assertTrue((bool) $event->consent_blocked);
        foreach ([$event->raw_payload, $snapshot->vendor_metadata] as $persistedPayload) {
            $this->assertSame('QUE-SAFE-ENVELOPE', $persistedPayload['imei'] ?? null);
            $this->assertSame('GTFRI', $persistedPayload['command_word'] ?? null);
            $this->assertSame(81, $persistedPayload['battery'] ?? null);
            $this->assertArrayNotHasKey('context', $persistedPayload);
            $this->assertArrayNotHasKey('structured', $persistedPayload);
            $this->assertPrivacyPayloadSanitized($persistedPayload);
            $this->assertStringNotContainsString('770001', json_encode($persistedPayload));
            $this->assertStringNotContainsString('770002', json_encode($persistedPayload));
            $this->assertStringNotContainsString('-41.91', json_encode($persistedPayload));
        }
    }

    public function test_task7_final_telemetry_worker_route_rechecks_assignment_after_vehicle_sos_before_side_effects(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 639;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedStaffTracker('QUE-LATE-WORKER-ASSIGNMENT', $worker, $tenantId);
        $session = $this->createLiveLoneWorkerSession($worker, $site);

        $this->mutateAfterVehicleSos(function () use ($device): void {
            DeviceAssignment::query()
                ->where('device_id', $device->id)
                ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
                ->whereNull('released_at')
                ->update(['released_at' => now()]);
        });

        $this->postStaffSos('QUE-LATE-WORKER-ASSIGNMENT');

        $this->assertSame('active', $session->fresh()->status);
        $this->assertOnlyVehicleSafetySignal($asset, $tracker, $device->id);
        $this->assertNoDerivedLocationArtifacts($asset);
    }

    public function test_task7_final_telemetry_late_worker_consent_withdrawal_masks_location_but_preserves_the_emergency(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 643;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site, 'consent' => $consent, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedStaffTracker('QUE-LATE-WORKER-CONSENT', $worker, $tenantId);
        $session = $this->createLiveLoneWorkerSession($worker, $site, [
            'location_lat' => -41.7,
            'location_lng' => 174.7,
        ]);

        $this->mutateAfterVehicleSos(function () use ($consent): void {
            ClientConsent::query()->whereKey($consent->id)->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
            ]);
        });

        $this->postStaffSos('QUE-LATE-WORKER-CONSENT');

        $freshSession = $session->fresh();
        $this->assertSame('emergency', $freshSession->status);
        $this->assertNull($freshSession->location_lat);
        $this->assertNull($freshSession->location_lng);
        $this->assertTrue(
            Signal::query()
                ->where('signal_type_code', LoneWorkerSignalService::TYPE_EMERGENCY)
                ->exists(),
        );
        $this->assertTelemetryPrivacySurfacesScrubbed($asset, $tracker, $device);
        $this->assertNoDerivedLocationArtifacts($asset);

        $vehicleSignal = FleetSignal::query()
            ->where('asset_id', $asset->id)
            ->where('signal_type', 'vehicle.sos')
            ->sole();
        $controlSignal = Signal::query()
            ->where('external_ref', "fleet_signal_{$vehicleSignal->id}")
            ->sole();
        $alert = $controlSignal->alert ?? $controlSignal->correlatedAlert;
        $this->assertNotNull($alert);
        $this->assertNull(data_get($alert->context, 'normalized_data.fleet_context.vehicle.name'));
        $this->assertNull(data_get($alert->context, 'normalized_data.fleet_context.vehicle.home_site'));
    }

    #[DataProvider('lateResidentLineageMutationProvider')]
    public function test_task7_final_telemetry_resident_route_rechecks_lineage_after_vehicle_sos(
        string $mutation,
        bool $deviceRemainsCanonical,
    ): void {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 640;
        $deviceUid = 'QUE-LATE-RESIDENT-'.strtoupper(str_replace('_', '-', $mutation));
        ['site' => $site, 'client' => $client, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedPersonalTracker($deviceUid, $tenantId);
        $otherClient = Client::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
        ]);
        $otherSite = Site::create(['name' => 'Other late resident Site']);

        $this->mutateAfterVehicleSos(function () use (
            $mutation,
            $device,
            $asset,
            $client,
            $otherSite,
            $otherClient,
        ): void {
            match ($mutation) {
                'client_assignment_released' => DeviceAssignment::query()
                    ->where('device_id', $device->id)
                    ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                    ->whereNull('released_at')
                    ->update(['released_at' => now()]),
                'asset_client_changed' => Asset::query()->whereKey($asset->id)->update(['client_id' => $otherClient->id]),
                'client_site_changed' => Client::query()->whereKey($client->id)->update(['site_id' => $otherSite->id]),
                default => throw new RuntimeException("Unknown late resident mutation: {$mutation}"),
            };
        });

        $this->postStaffSos($deviceUid);

        $this->assertOnlyVehicleSafetySignal(
            $asset,
            $tracker,
            $deviceRemainsCanonical ? $device->id : null,
        );
        $this->assertNoDerivedLocationArtifacts($asset);
    }

    public static function lateResidentLineageMutationProvider(): array
    {
        return [
            'active client assignment released' => ['client_assignment_released', true],
            'asset client changed' => ['asset_client_changed', true],
            'client moved to another Site' => ['client_site_changed', true],
        ];
    }

    public function test_task7_final_telemetry_late_consent_withdrawal_masks_every_surface_but_preserves_the_safety_alarm(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);
        Queue::fake();

        $tenantId = 641;
        ['client' => $client, 'consent' => $consent, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedPersonalTracker('QUE-LATE-CONSENT', $tenantId);
        $bookingUser = User::factory()->create(['organization_id' => $tenantId]);
        FleetVehicleBooking::factory()->create([
            'tenant_id' => $tenantId,
            'asset_id' => $asset->id,
            'user_id' => $bookingUser->id,
            'status' => 'checked_out',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->mutateAfterVehicleSos(function () use ($consent): void {
            ClientConsent::query()->whereKey($consent->id)->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
            ]);
        });

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-LATE-CONSENT',
                'gps_time' => now()->toISOString(),
                'alarm' => 'sos',
                'event_type' => 'vehicle_sos',
                'sos_flag' => true,
                'lat' => -41.8123,
                'lng' => 174.8123,
                'speed' => 140,
                'command_word' => 'GTSOS',
                'clientId' => $client->id,
                'resident-id' => 780001,
                'structured' => json_encode([
                    'personLocation' => ['latitude' => -41.8123, 'longitude' => 174.8123],
                ]),
            ])
            ->assertOk();

        // Production uses the database queue. Process the outboxes only after
        // the ingest transaction has persisted its privacy decision to prove a
        // delayed worker cannot rebuild the discarded resident/trip context.
        FleetSignalOutbox::query()
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $outboxId) => (new DispatchFleetSignalOutbox($outboxId))->handle());

        $this->assertDatabaseHas('fleet_signals', [
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'signal_type' => 'resident.sos',
        ]);
        $this->assertTelemetryPrivacySurfacesScrubbed($asset, $tracker, $device);
        $this->assertNoDerivedLocationArtifacts($asset);

        $vehicleSignal = FleetSignal::query()
            ->where('asset_id', $asset->id)
            ->where('signal_type', 'vehicle.sos')
            ->sole();
        $controlSignal = Signal::query()
            ->where('external_ref', "fleet_signal_{$vehicleSignal->id}")
            ->sole();
        $alert = $controlSignal->alert ?? $controlSignal->correlatedAlert;

        $this->assertNotNull($alert, 'The real base vehicle SOS must create or correlate a Control Room alert.');
        $this->assertPrivacyPayloadSanitized($controlSignal->payload ?? []);
        $this->assertPrivacyPayloadSanitized($controlSignal->normalized_data ?? []);
        $this->assertPrivacyPayloadSanitized($alert->context ?? []);
        $delayedControlSignals = Signal::query()
            ->whereIn('external_ref', FleetSignal::query()
                ->where('asset_id', $asset->id)
                ->pluck('id')
                ->map(fn (int $id): string => "fleet_signal_{$id}"))
            ->get();
        $this->assertCount(2, $delayedControlSignals);
        $delayedControlSignals->each(function (Signal $signal): void {
            $this->assertPrivacyPayloadSanitized($signal->payload ?? []);
            $this->assertPrivacyPayloadSanitized($signal->normalized_data ?? []);
            $derivedAlert = $signal->alert ?? $signal->correlatedAlert;
            $this->assertNotNull($derivedAlert);
            $this->assertPrivacyPayloadSanitized($derivedAlert->context ?? []);
        });
    }

    public function test_task7_final_telemetry_released_staff_history_suppresses_an_otherwise_valid_resident_route(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 642;
        ['client' => $client, 'consent' => $consent, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedPersonalTracker('QUE-RESIDENT-WITH-STAFF-HISTORY', $tenantId);
        $historicalWorker = User::factory()->create(['organization_id' => $tenantId]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $historicalWorker->id,
            'assigned_at' => now()->subDay(),
            'released_at' => now()->subHour(),
        ]);

        $this->assertTrue($consent->isValid());
        $this->assertSame($client->id, $asset->client_id);
        $this->assertTrue(DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereNull('released_at')
            ->exists());

        $this->postStaffSos('QUE-RESIDENT-WITH-STAFF-HISTORY');

        $this->assertOnlyVehicleSafetySignal($asset, $tracker, $device->id);
    }

    public function test_final_telemetry_acceptance_worker_route_locks_every_provenance_row_in_shared_order(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 635;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site] = $this->createStaffTracker(
            'QUE-FINAL-WORKER-LOCK-ORDER',
            $worker->id,
            $tenantId,
        );
        $sessionClient = Client::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
        ]);
        $distinctShiftClient = Client::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
            'client_id' => $distinctShiftClient->id,
            'user_id' => $worker->id,
        ]);
        $this->createLiveLoneWorkerSession($worker, $site, [
            'client_id' => $sessionClient->id,
            'shift_id' => $shift->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postStaffSos('QUE-FINAL-WORKER-LOCK-ORDER');
        $locks = $this->forUpdateQueries();
        DB::disableQueryLog();

        $relevantTables = $locks
            ->map(fn (array $entry): ?string => $this->lockedTable($entry['query']))
            ->filter(fn (?string $table): bool => in_array($table, [
                'device_assignments',
                'devices',
                'device_asset_links',
                'asset_trackers',
                'assets',
                'lone_worker_sessions',
                'users',
                'clients',
                'shifts',
                'sites',
            ], true))
            ->values();

        $this->assertSame([
            'device_assignments',
            'devices',
            'device_asset_links',
            'assets',
            'asset_trackers',
            'lone_worker_sessions',
            'users',
            'clients',
            'shifts',
            'clients',
            'sites',
        ], $relevantTables->all(), 'Worker routing must follow the shared H&S lock order exactly.');

        $sessionIndex = $relevantTables->search('lone_worker_sessions');
        $this->assertNotFalse($sessionIndex);
        $this->assertFalse(
            $relevantTables->take($sessionIndex)->contains('users'),
            'No worker row may be locked before the candidate session.',
        );
        $this->assertFalse(
            $relevantTables->take($sessionIndex)->contains('sites'),
            'No site row may be locked before the candidate session.',
        );
        $this->assertSame('users', $relevantTables->get($sessionIndex + 1), 'Worker must be re-fetched under lock after session.');
    }

    public function test_final_telemetry_acceptance_resident_route_keeps_its_separate_lock_path(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        ['asset' => $asset] = $this->createConsentedPersonalTracker(
            'QUE-FINAL-RESIDENT-LOCK-ORDER',
            636,
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postStaffSos('QUE-FINAL-RESIDENT-LOCK-ORDER');
        $locks = $this->forUpdateQueries();
        DB::disableQueryLog();

        $relevantTables = $locks
            ->map(fn (array $entry): ?string => $this->lockedTable($entry['query']))
            ->filter(fn (?string $table): bool => in_array($table, [
                'device_assignments',
                'devices',
                'device_asset_links',
                'asset_trackers',
                'assets',
                'lone_worker_sessions',
                'users',
                'clients',
                'shifts',
                'sites',
            ], true))
            ->values();

        $this->assertSame([
            'device_assignments',
            'devices',
            'device_asset_links',
            'assets',
            'asset_trackers',
            'sites',
            'clients',
        ], $relevantTables->all());
        $this->assertFalse($relevantTables->contains('lone_worker_sessions'));
        $this->assertFalse($relevantTables->contains('users'));
        $this->assertFalse($relevantTables->contains('shifts'));
        $this->assertDatabaseHas('fleet_signals', [
            'asset_id' => $asset->id,
            'signal_type' => 'resident.sos',
        ]);
    }

    #[DataProvider('invalidResidentProvenanceProvider')]
    public function test_final_sos_acceptance_rejects_invalid_resident_device_and_site_provenance(
        string $mutation,
        bool $deviceRemainsCanonical,
    ): void {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 628;
        $deviceUid = 'QUE-RESIDENT-'.strtoupper(str_replace('_', '-', $mutation));
        ['site' => $site, 'client' => $client, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device]
            = $this->createConsentedPersonalTracker($deviceUid, $tenantId);
        $otherSite = Site::create(['name' => 'Other resident provenance Site']);

        $this->mutateAfterInitialDeviceResolution($device, function () use (
            $client,
            $asset,
            $otherSite,
            $mutation,
        ): void {
            match ($mutation) {
                'client_site_changed' => Client::query()->whereKey($client->id)->update(['site_id' => $otherSite->id]),
                'asset_site_changed' => Asset::query()->whereKey($asset->id)->update(['site_id' => $otherSite->id]),
                default => throw new RuntimeException("Unknown resident mutation: {$mutation}"),
            };
        });

        $this->postStaffSos($deviceUid);

        $this->assertOnlyVehicleSafetySignal(
            $asset,
            $tracker,
            $deviceRemainsCanonical ? $device->id : null,
        );
    }

    public static function invalidResidentProvenanceProvider(): array
    {
        return [
            'client moved to another Site' => ['client_site_changed', false],
            'asset moved away from the client Site' => ['asset_site_changed', false],
        ];
    }

    #[DataProvider('sessionProvenanceMutationProvider')]
    public function test_final_sos_acceptance_rechecks_session_relationships_changed_before_lock(
        string $mutation,
    ): void {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 629;
        $foreignTenantId = 630;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site, 'asset' => $asset, 'tracker' => $tracker, 'device' => $device] = $this->createStaffTracker(
            'QUE-SESSION-PROVENANCE-'.strtoupper(str_replace('_', '-', $mutation)),
            $worker->id,
            $tenantId,
        );
        $client = Client::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $session = $this->createLiveLoneWorkerSession($worker, $site, [
            'client_id' => $client->id,
            'shift_id' => $shift->id,
        ]);
        $foreignSite = Site::create([
            'tenant_id' => $foreignTenantId,
            'name' => 'Foreign provenance site',
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => $foreignTenantId,
            'site_id' => $foreignSite->id,
        ]);
        $foreignShift = Shift::factory()->create([
            'organization_id' => $foreignTenantId,
            'site_id' => $foreignSite->id,
            'client_id' => $foreignClient->id,
            'user_id' => $worker->id,
        ]);

        $this->mutateAfterVehicleSos(function () use (
            $session,
            $shift,
            $foreignSite,
            $foreignClient,
            $foreignShift,
            $mutation,
        ): void {
            match ($mutation) {
                'session_site' => DB::table('lone_worker_sessions')->where('id', $session->id)->update(['site_id' => $foreignSite->id]),
                'session_client' => DB::table('lone_worker_sessions')->where('id', $session->id)->update(['client_id' => $foreignClient->id]),
                'session_shift' => DB::table('lone_worker_sessions')->where('id', $session->id)->update(['shift_id' => $foreignShift->id]),
                'shift_client' => DB::table('shifts')->where('id', $shift->id)->update(['client_id' => $foreignClient->id]),
                default => throw new RuntimeException("Unknown session mutation: {$mutation}"),
            };
        });

        $this->postStaffSos('QUE-SESSION-PROVENANCE-'.strtoupper(str_replace('_', '-', $mutation)));

        $this->assertSosRemainsDeviceOnly($asset, $tracker, $device->id, $worker, $session);
    }

    public static function sessionProvenanceMutationProvider(): array
    {
        return [
            'session site changed' => ['session_site'],
            'session client changed' => ['session_client'],
            'session shift changed' => ['session_shift'],
            'shift client changed' => ['shift_client'],
        ];
    }

    public function test_final_sos_acceptance_locks_session_provenance_in_hs_order(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 631;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site] = $this->createStaffTracker(
            'QUE-SESSION-PROVENANCE-LOCKS',
            $worker->id,
            $tenantId,
        );
        $client = Client::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $this->createLiveLoneWorkerSession($worker, $site, [
            'client_id' => $client->id,
            'shift_id' => $shift->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postStaffSos('QUE-SESSION-PROVENANCE-LOCKS');
        $locks = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query))
            ->filter(fn (string $query): bool => str_contains($query, 'for update'))
            ->values();
        DB::disableQueryLog();

        $session = $locks->search(fn (string $query): bool => str_contains($query, 'from `lone_worker_sessions`'));
        $client = $locks->search(fn (string $query, int $index): bool => $index > $session && str_contains($query, 'from `clients`'));
        $shift = $locks->search(fn (string $query, int $index): bool => $index > $session && str_contains($query, 'from `shifts`'));
        $site = $locks->search(fn (string $query, int $index): bool => $index > $session && str_contains($query, 'from `sites`'));

        $this->assertNotFalse($session, 'The candidate session must be locked first.');
        $this->assertNotFalse($client, 'Session client provenance must be locked after the session.');
        $this->assertNotFalse($shift, 'Session shift provenance must be locked after its client.');
        $this->assertNotFalse($site, 'The resolved session site must be re-fetched under lock last.');
        $this->assertLessThan($client, $session);
        $this->assertLessThan($shift, $client);
        $this->assertLessThan($site, $shift);
    }

    public function test_staff_sos_locks_assignment_device_and_session_in_the_shared_order(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $tenantId = 618;
        $worker = User::factory()->create(['organization_id' => $tenantId]);
        ['site' => $site] = $this->createStaffTracker(
            'QUE-STAFF-LOCK-ORDER',
            $worker->id,
            $tenantId,
        );
        LoneWorkerSession::create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHour(),
            'last_check_in_at' => now()->subMinutes(20),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-STAFF-LOCK-ORDER',
                'gps_time' => now()->toISOString(),
                'alarm' => 'sos',
                'event_type' => 'man_down',
                'sos_flag' => true,
                'command_word' => 'GTMAN',
            ])
            ->assertOk();
        $locks = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query))
            ->filter(fn (string $query): bool => str_contains($query, 'for update'))
            ->values();
        DB::disableQueryLog();

        $assignment = $locks->search(fn (string $query): bool => str_contains(
            $query,
            'from `device_assignments`',
        ));
        $device = $locks->search(fn (string $query): bool => str_contains(
            $query,
            'select * from `devices`',
        ));
        $session = $locks->search(fn (string $query): bool => str_contains(
            $query,
            'from `lone_worker_sessions`',
        ));

        $this->assertNotFalse($assignment, 'Staff SOS must lock its active assignment.');
        $this->assertNotFalse($device, 'Staff SOS must re-fetch and lock its canonical device.');
        $this->assertNotFalse($session, 'Staff SOS must lock and revalidate its live session.');
        $this->assertLessThan($device, $assignment);
        $this->assertLessThan($session, $device);
    }

    public function test_ingest_retries_a_simulated_deadlock_without_duplicate_side_effects(): void
    {
        $service = app(FleetTelemetryIngestService::class);
        $method = new \ReflectionMethod($service, 'withinIngestTransaction');
        $defaultConnection = DB::getDefaultConnection();
        $probeConnection = 'telemetry_retry_probe';
        config([
            "database.connections.{$probeConnection}" => config("database.connections.{$defaultConnection}"),
        ]);
        DB::purge($probeConnection);
        DB::setDefaultConnection($probeConnection);
        $transactionAttempts = 0;
        try {
            DB::statement('CREATE TEMPORARY TABLE telemetry_retry_effects (id INT PRIMARY KEY)');
            $result = $method->invoke($service, function () use (
                &$transactionAttempts,
                $probeConnection,
            ): array {
                $transactionAttempts++;
                DB::table('telemetry_retry_effects')->insert(['id' => 1]);
                if ($transactionAttempts === 1) {
                    throw new QueryException(
                        $probeConnection,
                        'insert into telemetry_retry_effects (id) values (1)',
                        [],
                        new RuntimeException('Deadlock found when trying to get lock'),
                    );
                }

                return ['ok' => true];
            });
            $committedEffects = DB::table('telemetry_retry_effects')->count();
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS telemetry_retry_effects');
            DB::disconnect($probeConnection);
            DB::setDefaultConnection($defaultConnection);
        }

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(2, $transactionAttempts);
        $this->assertSame(1, $committedEffects);
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

    public function test_gl30_location_health_report_does_not_clear_last_known_charging_state(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        ['device' => $device] = $this->createConsentedPersonalTracker('QUE-HEALTH-CHARGE-FRI');

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-HEALTH-CHARGE-FRI',
                'gps_time' => now()->subMinute()->toISOString(),
                'event_type' => 'charging_started',
                'battery' => 95,
                'battery_voltage_mv' => 4066,
                'external_power' => true,
                'charging_status' => 'charging',
                'command_word' => 'GTBTC',
            ])
            ->assertStatus(200);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-HEALTH-CHARGE-FRI',
                'gps_time' => now()->toISOString(),
                'event_type' => 'location_report',
                'battery' => 95,
                'battery_voltage_mv' => 4067,
                'charging_status' => null,
                'command_word' => 'GTFRI',
            ])
            ->assertStatus(200);

        $device->refresh();

        $this->assertSame(95, $device->battery_level);
        $this->assertSame(95, $device->meta['battery']);
        $this->assertSame(4067, $device->meta['battery_voltage_mv']);
        $this->assertSame('charging', $device->meta['charging_status']);
        $this->assertTrue($device->meta['external_power']);
        $this->assertSame('Charging', $device->meta['battery_status_label']);
    }

    /**
     * A Queclink personal tracker paired to a STAFF member (lone worker) — no client,
     * no consent; the canonical link is a TARGET_STAFF DeviceAssignment.
     *
     * @return array{site: Site, consent: ClientConsent, asset: Asset, tracker: AssetTracker, device: Device}
     */
    private function createStaffTracker(string $deviceUid, int $userId, int $tenantId): array
    {
        $site = Site::create([
            'tenant_id' => $tenantId,
            'name' => 'Field Base',
        ]);
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => "Lone-worker tracker {$deviceUid}",
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
            'tenant_id' => $tenantId,
            'provider' => 'queclink',
            'imei' => $deviceUid,
            'device_uid' => $deviceUid,
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        $this->linkCanonicalDevice($device, $asset);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'staff',
            'assignable_id' => $userId,
            'assigned_at' => now(),
        ]);

        return compact('site', 'asset', 'tracker', 'device');
    }

    /**
     * Build a valid staff route with explicit tracking consent so a post-SOS
     * mutation proves that the final freshness gate, rather than the initial
     * privacy gate, removed the person-attributed side effects.
     *
     * @return array{site: Site, asset: Asset, tracker: AssetTracker, device: Device}
     */
    private function createConsentedStaffTracker(string $deviceUid, User $worker, int $tenantId): array
    {
        $fixture = $this->createConsentedPersonalTracker($deviceUid, $tenantId);

        DeviceAssignment::query()
            ->where('device_id', $fixture['device']->id)
            ->delete();
        $fixture['asset']->update(['client_id' => null]);
        DeviceAssignment::create([
            'device_id' => $fixture['device']->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $worker->id,
            'assigned_at' => now(),
        ]);

        return [
            'site' => $fixture['site'],
            'consent' => $fixture['consent'],
            'asset' => $fixture['asset']->fresh(),
            'tracker' => $fixture['tracker']->fresh(),
            'device' => $fixture['device']->fresh(),
        ];
    }

    private function createLiveLoneWorkerSession(User $worker, Site $site, array $overrides = []): LoneWorkerSession
    {
        $this->makeCurrentWorkerAtSite($worker, $site);

        return LoneWorkerSession::create(array_merge([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHour(),
            'last_check_in_at' => now()->subMinutes(20),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ], $overrides));
    }

    private function makeCurrentWorkerAtSite(User $worker, Site $site): void
    {
        $worker->forceFill([
            'approved_at' => $worker->approved_at ?? now(),
            'role' => 'support_worker',
        ])->save();

        $profile = HrEmployeeProfile::query()->where('user_id', $worker->id)->first();
        if ($profile) {
            $profile->forceFill([
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => $profile->start_date ?? now()->subDay(),
                'end_date' => null,
            ])->save();
        } else {
            HrEmployeeProfile::factory()->create([
                'user_id' => $worker->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => now()->subDay(),
                'end_date' => null,
            ]);
        }

        $worker->unsetRelation('hrEmployeeProfile');
    }

    private function postStaffSos(string $deviceUid): void
    {
        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => $deviceUid,
                'gps_time' => now()->toISOString(),
                'alarm' => 'sos',
                'event_type' => 'man_down',
                'sos_flag' => true,
                'lat' => -41.5,
                'lng' => 174.5,
                'command_word' => 'GTMAN',
            ])
            ->assertOk();
    }

    private function mutateAfterInitialDeviceResolution(Device $device, \Closure $mutation): void
    {
        $runtime = new class($device->id, $mutation) extends FleetDeviceRuntimeService
        {
            public function __construct(
                private readonly int $deviceId,
                private readonly \Closure $mutation,
            ) {}

            public function resolveCanonicalDevice(
                string $vendor,
                array $normalized,
                ?AssetTracker $tracker = null,
            ): ?Device {
                $resolved = Device::query()->findOrFail($this->deviceId);
                ($this->mutation)();

                return $resolved;
            }
        };

        $this->app->instance(FleetDeviceRuntimeService::class, $runtime);
    }

    private function mutateAfterVehicleSos(\Closure $mutation): void
    {
        $signals = new class($mutation) extends FleetSignalService
        {
            private bool $mutated = false;

            public function __construct(private readonly \Closure $mutation) {}

            public function emit(array $payload): FleetSignal
            {
                $signal = parent::emit($payload);

                if (! $this->mutated && ($payload['signal_type'] ?? null) === 'vehicle.sos') {
                    $this->mutated = true;
                    ($this->mutation)();
                }

                return $signal;
            }
        };

        $this->app->instance(FleetSignalService::class, $signals);
    }

    private function assertSosRemainsDeviceOnly(
        Asset $asset,
        AssetTracker $tracker,
        ?int $expectedDeviceId,
        User $worker,
        LoneWorkerSession $session,
    ): void {
        $freshSession = $session->fresh();
        $this->assertSame('active', $freshSession->status);
        $this->assertNull($freshSession->emergency_triggered_at);

        $this->assertOnlyVehicleSafetySignal($asset, $tracker, $expectedDeviceId);

        $this->assertFalse(LoneWorkerAlert::query()->where('lone_worker_session_id', $session->id)->exists());

        $this->assertSame($worker->id, $freshSession->user_id);
    }

    private function assertOnlyVehicleSafetySignal(
        Asset $asset,
        AssetTracker $tracker,
        ?int $expectedDeviceId,
    ): void {

        $vehicleSignal = FleetSignal::query()
            ->where('asset_id', $asset->id)
            ->where('asset_tracker_id', $tracker->id)
            ->where('signal_type', 'vehicle.sos')
            ->sole();
        $event = FleetTelemetryEvent::query()
            ->where('asset_id', $asset->id)
            ->where('asset_tracker_id', $tracker->id)
            ->sole();
        $snapshot = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('asset_tracker_id', $tracker->id)
            ->sole();
        $state = FleetVehicleStateSnapshot::query()->findOrFail($asset->id);

        $this->assertSame($expectedDeviceId, $vehicleSignal->device_id);
        $this->assertSame($expectedDeviceId, $event->device_id);
        foreach (['latitude', 'longitude', 'accuracy_m', 'speed_kph', 'heading_deg', 'altitude_m'] as $field) {
            $this->assertNull($event->{$field}, "Rejected person attribution must clear event {$field}.");
        }
        foreach (['latitude', 'longitude', 'accuracy_m', 'speed_kph'] as $field) {
            $this->assertNull($snapshot->{$field}, "Rejected person attribution must clear snapshot {$field}.");
        }
        foreach (['latitude', 'longitude', 'speed_kph', 'heading_deg'] as $field) {
            $this->assertNull($state->{$field}, "Rejected person attribution must clear state {$field}.");
        }
        $this->assertSame(1, FleetSignal::query()->where('signal_type', 'vehicle.sos')->count());
        $this->assertDatabaseMissing('fleet_signals', ['signal_type' => 'lone_worker.sos']);
        $this->assertDatabaseMissing('fleet_signals', ['signal_type' => 'resident.sos']);
        $this->assertFalse(ControlRoomAlert::query()->where('source', 'lone_worker')->exists());
        $this->assertFalse(
            Signal::query()
                ->where('signal_type_code', LoneWorkerSignalService::TYPE_EMERGENCY)
                ->exists(),
        );
        $this->assertFalse(LoneWorkerAlert::query()->exists());

        foreach ([$event->raw_payload, $snapshot->vendor_metadata, $vehicleSignal->payload] as $context) {
            foreach (['worker_user_id', 'lone_worker_session_id', 'client_id', 'resident_id', 'device_id'] as $key) {
                $this->assertArrayNotHasKey($key, $context ?? []);
                $this->assertStringNotContainsString($key, json_encode($context));
            }

            $this->assertSensitivePersonAndLocationPayloadRemoved($context ?? []);
        }
    }

    private function assertTelemetryPrivacySurfacesScrubbed(
        Asset $asset,
        AssetTracker $tracker,
        Device $device,
    ): void {
        $event = FleetTelemetryEvent::query()
            ->where('asset_id', $asset->id)
            ->where('asset_tracker_id', $tracker->id)
            ->sole();
        $snapshot = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('asset_tracker_id', $tracker->id)
            ->sole();
        $state = FleetVehicleStateSnapshot::query()->findOrFail($asset->id);

        $this->assertTrue((bool) $event->consent_blocked);
        $this->assertTrue((bool) $snapshot->consent_blocked);
        $this->assertTrue((bool) $state->consent_blocked);
        foreach (['latitude', 'longitude', 'accuracy_m', 'speed_kph', 'heading_deg', 'altitude_m'] as $field) {
            $this->assertNull($event->{$field}, "Privacy-blocked event {$field} must be null.");
        }
        foreach (['latitude', 'longitude', 'accuracy_m', 'speed_kph'] as $field) {
            $this->assertNull($snapshot->{$field}, "Privacy-blocked snapshot {$field} must be null.");
        }
        foreach (['latitude', 'longitude', 'speed_kph', 'heading_deg'] as $field) {
            $this->assertNull($state->{$field}, "Privacy-blocked state {$field} must be null.");
        }

        $device->refresh();
        $this->assertNull($device->latitude);
        $this->assertNull($device->longitude);
        $this->assertPrivacyPayloadSanitized($event->raw_payload ?? []);
        $this->assertPrivacyPayloadSanitized($snapshot->vendor_metadata ?? []);
    }

    private function assertNoDerivedLocationArtifacts(Asset $asset): void
    {
        $this->assertDatabaseMissing('fleet_trips', ['asset_id' => $asset->id]);
        $this->assertDatabaseMissing('fleet_driving_metrics', ['asset_id' => $asset->id]);
    }

    private function assertPrivacyPayloadSanitized(array $payload): void
    {
        $deniedKeys = array_map(
            fn (string $key): string => $this->normalizePrivacyKey($key),
            [
                'client_id',
                'resident_id',
                'staff_id',
                'worker_id',
                'worker_user_id',
                'lone_worker_session_id',
                'session_id',
                'user_id',
                'assigned_user_id',
                'primary_driver_user_id',
                'booked_by_user_id',
                'person_location',
                'person',
                'resident',
                'worker',
                'driver',
                'session',
                'user',
                'client',
                'location',
                'position',
                'coordinates',
                'gps',
                'lat',
                'latitude',
                'lng',
                'lon',
                'longitude',
                'speed',
                'speed_kph',
                'speed_kn',
                'heading',
                'heading_deg',
                'course',
                'accuracy',
                'accuracy_m',
                'hdop',
                'altitude',
                'altitude_m',
                'last_location_at',
            ],
        );

        foreach ($this->privacyPayloadKeys($payload) as $key) {
            $this->assertNotContains(
                $key,
                $deniedKeys,
                "Privacy-blocked payload retained sensitive key {$key}.",
            );
        }
    }

    /** @return list<string> */
    private function privacyPayloadKeys(array $payload): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            $keys[] = $this->normalizePrivacyKey((string) $key);

            if (is_array($value)) {
                array_push($keys, ...$this->privacyPayloadKeys($value));
            } elseif (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    array_push($keys, ...$this->privacyPayloadKeys($decoded));
                }
            }
        }

        return $keys;
    }

    private function normalizePrivacyKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
    }

    private function assertSensitivePersonAndLocationPayloadRemoved(array $payload): void
    {
        $json = strtolower((string) json_encode($payload));
        foreach ([
            'client_id',
            'resident_id',
            'worker_id',
            'worker_user_id',
            'lone_worker_session_id',
            'session_id',
            'user_id',
            'assigned_user_id',
            'primary_driver_user_id',
            'person',
            'resident',
            'worker',
            'session',
            'user',
            'client',
            'location',
            'gps',
            'lat',
            'latitude',
            'lng',
            'lon',
            'longitude',
            'speed',
            'speed_kph',
            'speed_kn',
            'heading',
            'heading_deg',
            'course',
            'accuracy',
            'accuracy_m',
            'hdop',
            'altitude',
            'altitude_m',
            'last_location_at',
        ] as $deniedKey) {
            $this->assertStringNotContainsString('"'.strtolower($deniedKey).'"', $json);
        }
    }

    private function forUpdateQueries(): Collection
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains(strtolower($entry['query']), 'for update'))
            ->values();
    }

    private function lockedTable(string $query): ?string
    {
        preg_match('/\bfrom\s+[`"]?([a-z0-9_]+)[`"]?/i', $query, $matches);

        return $matches[1] ?? null;
    }

    private function createCanonicalDevice(
        string $deviceUid,
        Asset $asset,
        ?AssetTracker $historicalTracker = null,
    ): Device {
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => $deviceUid,
            'device_uid' => $deviceUid,
            'legacy_asset_tracker_id' => $historicalTracker?->id,
        ]);
        $this->linkCanonicalDevice($device, $asset);

        return $device;
    }

    private function linkCanonicalDevice(Device $device, Asset $asset): DeviceAssetLink
    {
        return DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);
    }

    /**
     * @return array{site: Site, client: Client, consent: ClientConsent, asset: Asset, tracker: AssetTracker, device: Device}
     */
    private function createConsentedPersonalTracker(string $deviceUid, int $tenantId = 632): array
    {
        $site = Site::create([
            'tenant_id' => $tenantId,
            'name' => 'Harbour Respite',
        ]);
        $client = Client::create([
            'organization_id' => $tenantId,
            'site_id' => $site->id,
            'first_name' => 'Amelia',
            'last_name' => 'Wilson',
        ]);
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
            'tenant_id' => $tenantId,
            'provider' => 'queclink',
            'imei' => $deviceUid,
            'device_uid' => $deviceUid,
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        $this->linkCanonicalDevice($device, $asset);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $consent->id,
        ]);

        return compact('site', 'client', 'consent', 'asset', 'tracker', 'device');
    }
}
