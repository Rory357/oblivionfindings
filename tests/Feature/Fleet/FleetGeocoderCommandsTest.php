<?php

namespace Tests\Feature\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Jobs\ReverseGeocodeFleetTelemetryEvent;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\FleetTelemetryEvent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetGeocoderCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_geocoder_status_reports_nominatim_health_and_only_fails_when_requested(): void
    {
        config([
            'fleet.maps.reverse_geocode_enabled' => true,
            'fleet.maps.reverse_geocode_provider' => 'nominatim',
            'fleet.maps.nominatim.endpoint' => 'http://nominatim.test',
        ]);

        Http::fake([
            'nominatim.test/status' => Http::response(['status' => 'down'], 503),
        ]);

        $this->artisan('fleet:geocoder:status')
            ->expectsOutputToContain('Provider: nominatim')
            ->expectsOutputToContain('Enabled: yes')
            ->expectsOutputToContain('Endpoint: http://nominatim.test')
            ->expectsOutputToContain('Health: unhealthy')
            ->assertExitCode(0);

        $this->artisan('fleet:geocoder:status --fail-if-enabled')
            ->expectsOutputToContain('Health: unhealthy')
            ->assertExitCode(1);
    }

    public function test_reverse_geocode_backfill_dry_run_counts_eligible_rows_without_queueing_jobs(): void
    {
        Queue::fake();

        ['client' => $client, 'device' => $device] = $this->createResidentTracker('GEO-BACKFILL-1');
        $this->createTelemetryEvent($device, ['occurred_at' => now()]);
        $this->createTelemetryEvent($device, [
            'occurred_at' => now()->subMinute(),
            'consent_blocked' => true,
        ]);
        $this->createTelemetryEvent($device, [
            'occurred_at' => now()->subMinutes(2),
            'reverse_geocode_failed_at' => now(),
        ]);
        $this->createTelemetryEvent($device, [
            'occurred_at' => now()->subMinutes(3),
            'address' => 'Already resolved',
            'reverse_geocoded_at' => now(),
        ]);

        $this->artisan("fleet:reverse-geocode:backfill --client={$client->id} --limit=10 --dry-run")
            ->expectsOutputToContain('Eligible rows: 1')
            ->expectsOutputToContain('Dry run: no jobs queued')
            ->assertExitCode(0);

        Queue::assertNotPushed(ReverseGeocodeFleetTelemetryEvent::class);
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

        return compact('site', 'client', 'asset', 'tracker', 'device');
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
