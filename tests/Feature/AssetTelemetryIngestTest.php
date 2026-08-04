<?php

namespace Tests\Feature;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTelemetryIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_requires_token_or_permission(): void
    {
        $response = $this->postJson('/telemetry/ingest/quicklink', []);
        $response->assertStatus(403);
    }

    public function test_ingest_with_token_creates_snapshot(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Test Site']);
        $asset = Asset::create([
            'site_id' => $site->id,
            'name' => 'Wheelchair',
            'status' => 'active',
            'risk_level' => 'high',
        ]);

        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'quicklink',
            'device_uid' => 'DEV-123',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'quicklink',
            'device_uid' => 'DEV-123',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->withHeader('X-Telemetry-Token', 'test-token')->postJson('/telemetry/ingest/quicklink', [
            'device_uid' => 'DEV-123',
            'timestamp' => now()->toISOString(),
            'lat' => -41.0,
            'lon' => 174.0,
            'speed' => 0,
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('asset_telemetry_snapshots', [
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
        ]);
    }

    public function test_queclink_ingest_uses_canonical_link_and_ignores_conflicting_historical_lineage(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $site = Site::create(['name' => 'Canonical telemetry Site']);
        $canonicalAsset = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $historicalAsset = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $tracker = AssetTracker::create([
            'asset_id' => $historicalAsset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-CANONICAL-001',
            'imei' => 'QUE-CANONICAL-001',
            'status' => 'paired',
            'paired_at' => now()->subDay(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'device_uid' => 'QUE-CANONICAL-001',
            'imei' => 'QUE-CANONICAL-001',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $canonicalAsset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-CANONICAL-001',
                'gps_time' => now()->toISOString(),
                'lat' => -41.0,
                'lng' => 174.0,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('fleet_telemetry_events', [
            'asset_id' => $canonicalAsset->id,
            'asset_tracker_id' => null,
            'device_id' => $device->id,
        ]);
        $this->assertSame($historicalAsset->id, $tracker->fresh()->asset_id);
        $this->assertSame('paired', $tracker->fresh()->status);
    }

    public function test_queclink_ingest_rejects_a_released_canonical_asset_link(): void
    {
        config(['services.telemetry.ingest_token' => 'test-token']);

        $asset = Asset::factory()->vehicle()->create();
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'device_uid' => 'QUE-RELEASED-001',
            'imei' => 'QUE-RELEASED-001',
        ]);
        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subDay(),
            'unlinked_at' => now(),
        ]);

        $this->withHeader('X-Telemetry-Token', 'test-token')
            ->postJson('/telemetry/ingest/queclink', [
                'imei' => 'QUE-RELEASED-001',
                'gps_time' => now()->toISOString(),
                'lat' => -41.0,
                'lng' => 174.0,
            ])
            ->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'error' => 'canonical device asset link unavailable or ambiguous',
            ]);

        $this->assertDatabaseCount('fleet_telemetry_events', 0);
    }
}
