<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Asset;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Services\Integration\IntegrationEventHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsolidatedIntegrationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public static function interactiveCases(): iterable
    {
        foreach (['integration_events', 'fleet_telemetry_events'] as $source) {
            yield $source.' exact cap' => [$source, 500, false, 500, false];
            yield $source.' overflow' => [$source, 501, false, 500, true];
            yield $source.' invalid saturation' => [$source, 501, true, 0, true];
        }
    }

    #[DataProvider('interactiveCases')]
    public function test_interactive_scan_reports_saturation_before_invalid_rows_are_filtered(string $source, int $count, bool $invalid, int $expected, bool $truncated): void
    {
        $device = $this->device();
        $this->insertObservations($source, $device, $count, $invalid);
        $window = app(IntegrationEventHistoryService::class)->forDeviceWindow($device, [], 90);
        $this->assertCount($expected, $window['positions']);
        $this->assertSame($truncated, $window['truncated']);
        $this->assertCount($expected, app(IntegrationEventHistoryService::class)->forDevice($device));
    }

    public static function sources(): iterable
    {
        yield 'integration' => ['integration_events'];
        yield 'fleet telemetry' => ['fleet_telemetry_events'];
    }

    #[DataProvider('sources')]
    public function test_report_stream_keeps_601_original_contributors_and_honours_late_arrival_and_zero_watermarks(string $source): void
    {
        $device = $this->device();
        $this->insertObservations($source, $device, 601);
        $reader = app(IntegrationEventHistoryService::class);
        $watermark = $reader->reportWatermark();
        $this->insertObservations($source, $device, 1);
        $seen = [];
        $rows = $reader->reportForDevice($device, [], 90, $watermark, observe: function ($model) use (&$seen): void {
            $seen[$model->getKey()] = $model->getRawOriginal();
        })->collect();
        $this->assertCount(601, $rows);
        $this->assertCount(601, $seen);
        $this->assertCount(601, $rows->unique('source_id'));
        $this->assertSame([$source], $rows->pluck('source')->unique()->values()->all());
        $this->assertLessThanOrEqual($watermark[$source], $rows->max('source_id'));
        $this->assertArrayHasKey('raw_payload', reset($seen));
        $this->assertStringContainsString('original-contributor', (string) reset($seen)['raw_payload']);
        $this->assertEmpty($reader->reportForDevice($device, [], 90, ['integration_events' => 0, 'fleet_telemetry_events' => 0])->collect());
        $window = $reader->forDeviceWindow($device, [], 90);
        $this->assertCount(500, $window['positions']);
        $this->assertTrue($window['truncated']);
    }

    public function test_exclusive_before_and_accuracy_precedence_survive_both_read_modes(): void
    {
        $device = $this->device();
        $this->insertObservations('integration_events', $device, 1);
        $id = DB::table('integration_events')->max('id');
        $before = now()->startOfSecond();
        DB::table('integration_events')->where('id', $id)->update([
            'occurred_at' => $before,
            'raw_payload' => json_encode(['lat' => -36.8, 'lng' => 174.7, 'accuracy_m' => 8, 'accuracy' => 90, 'location' => ['accuracy' => 95]]),
        ]);
        $reader = app(IntegrationEventHistoryService::class);
        $watermark = $reader->reportWatermark();
        $this->assertSame(8, $reader->forDeviceWindow($device, [], 90)['positions']->first()['accuracy']);
        $this->assertSame(8, $reader->reportForDevice($device, [], 90, $watermark)->first()['accuracy']);
        $this->assertNotNull($reader->reportForDevice($device, [], 90, $watermark)->first()['received_at']);
        $this->assertEmpty($reader->forDeviceWindow($device, ['before' => $before], 90)['positions']);
        $this->assertEmpty($reader->reportForDevice($device, ['before' => $before], 90, $watermark)->collect());
        DB::table('integration_events')->where('id', $id)->update(['raw_payload' => json_encode(['lat' => -36.8, 'lng' => 174.7, 'location' => ['accuracy' => 12]])]);
        $this->assertSame(12, $reader->forDeviceWindow($device, [], 90)['positions']->first()['accuracy']);
    }

    public function test_legacy_ambiguity_outside_allowlist_including_deleted_devices_is_denied_with_one_batched_lookup(): void
    {
        $site = Site::factory()->create();
        $devices = collect();
        foreach (range(1, 12) as $index) {
            $hardware = LocationHardware::create(['site_id' => $site->id, 'provider' => 'qa', 'category' => LocationHardware::CATEGORY_TRACKER, 'name' => 'Synthetic legacy '.$index, 'status' => LocationHardware::STATUS_ONLINE]);
            $device = $this->device(['legacy_location_hardware_id' => $hardware->id]);
            $devices->push($device);
            $this->insertObservations('integration_events', $device, 1);
            DB::table('integration_events')->where('canonical_device_id', $device->id)->update(['canonical_device_id' => null, 'hardware_id' => $hardware->id]);
        }
        $duplicate = $this->device(['legacy_location_hardware_id' => $devices->first()->legacy_location_hardware_id]);
        $duplicate->delete();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $rows = app(IntegrationEventHistoryService::class)->forDevices($devices, includeEventType: true, retentionDays: 90);
        $this->assertCount(11, $rows);
        $this->assertNotContains($devices->first()->id, $rows->pluck('device_id')->all());
        $this->assertCount(1, array_filter($queries, fn ($sql): bool => str_contains($sql, 'group by') && str_contains($sql, 'legacy_location_hardware_id')));
        $this->assertCount(1, array_filter($queries, fn ($sql): bool => preg_match('/from\s+[\x60\"]?integration_events\b/', $sql) === 1));
        $this->assertCount(1, array_filter($queries, fn ($sql): bool => preg_match('/from\s+[\x60\"]?fleet_telemetry_events\b/', $sql) === 1));
    }

    private function device(array $attributes = []): Device
    {
        return Device::factory()->tracking()->create(array_merge(['legacy_location_hardware_id' => null, 'legacy_asset_tracker_id' => null], $attributes));
    }

    private function insertObservations(string $source, Device $device, int $count, bool $invalid = false): void
    {
        $site = Site::factory()->create();
        $asset = $source === 'fleet_telemetry_events' ? Asset::factory()->forSite($site)->create() : null;
        $now = now()->startOfSecond();
        $rows = [];
        foreach (range(1, $count) as $index) {
            $payload = ['lat' => $invalid ? 120 : -36.8, 'lng' => 174.7, 'marker' => 'original-contributor'];
            $shared = ['occurred_at' => $now, 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'event_type' => 'location_update', 'raw_payload' => json_encode($payload)];
            $rows[] = $source === 'integration_events'
                ? [...$shared, 'site_id' => $site->id, 'canonical_device_id' => $device->id, 'hardware_id' => null, 'provider' => 'consolidation', 'source_app' => 'consolidation', 'source_event_id' => Str::uuid()->toString(), 'severity' => 'info', 'tags' => '[]', 'normalized_payload' => '{}']
                : [...$shared, 'asset_id' => $asset->id, 'device_id' => $device->id, 'asset_tracker_id' => null, 'vendor' => 'consolidation', 'latitude' => $payload['lat'], 'longitude' => $payload['lng'], 'consent_blocked' => false, 'idempotency_key' => hash('sha256', Str::uuid()->toString())];
        }
        if ($source === 'integration_events') {
            foreach ($rows as $row) {
                // Exercise the model creating hook for required compatibility fields.
                $event = new IntegrationEvent;
                $event->setRawAttributes($row);
                $event->save();
            }

            return;
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table($source)->insert($chunk);
        }
    }
}
