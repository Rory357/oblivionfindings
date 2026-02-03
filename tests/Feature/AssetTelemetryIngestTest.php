<?php

namespace Tests\Feature;

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
        ]);
    }
}
