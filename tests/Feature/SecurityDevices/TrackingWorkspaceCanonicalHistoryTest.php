<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\TrackingWorkspacePresenter;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FleetTelemetryEvent;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\IntegrationEventHistoryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrackingWorkspaceCanonicalHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_canonical_personal_history_is_hidden_after_withdrawal_without_affecting_operational_history(): void
    {
        $site = Site::factory()->create(['name' => 'Canonical Tracking History Site']);
        $viewer = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'admin')->sole()->id);

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'preferred_name' => 'Synthetic Client',
            'status' => 'active',
        ]);
        $consentType = ConsentType::factory()->create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'purpose' => 'Client personal safety tracking',
            'active' => true,
        ]);
        $consent = ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'status' => 'given',
            'given_at' => now()->subHour(),
            'given_by_user_id' => $viewer->id,
            'given_method' => 'written',
            'expires_at' => now()->addMonth(),
            'created_by' => $viewer->id,
            'updated_by' => $viewer->id,
        ]);
        $personal = Device::factory()->tracking()->create([
            'name' => 'Synthetic personal tracker',
            'category' => 'personal_tracker',
            'latitude' => -41.0,
            'longitude' => 174.0,
            'last_seen_at' => now()->subMinute(),
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $personal->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subHour(),
            'assigned_by_user_id' => $viewer->id,
            'consent_id' => $consent->id,
            'tracking_purpose' => 'Client personal safety tracking',
            'authority_basis' => 'assignment_linked_client_consent',
            'access_audience' => ['authorised_client_care', 'control_room'],
            'retention_days' => 90,
            'collection_started_at' => now()->subHour(),
        ]);
        $personalEvent = IntegrationEvent::factory()->create([
            'site_id' => $site->id,
            'canonical_device_id' => $personal->id,
            'provider' => 'release_fixture',
            'source_app' => 'desktop_release_acceptance',
            'source_event_id' => 'synthetic-personal-position',
            'occurred_at' => now()->subMinutes(5),
            'received_at' => now()->subMinutes(5),
            'severity' => 'info',
            'event_type' => 'position',
            'tags' => ['synthetic' => true],
            'normalized_payload' => ['lat' => -41.0, 'lng' => 174.0, 'battery_pct' => 80],
            'raw_payload' => null,
        ]);

        $vehicle = Asset::factory()->forSite($site)->create([
            'name' => 'Operational vehicle',
            'category' => 'Vehicle',
        ]);
        $fleet = Device::factory()->tracking()->create([
            'name' => 'Operational fleet tracker',
            'category' => 'vehicle_tracker',
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $fleet->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subHour(),
            'linked_by_user_id' => $viewer->id,
        ]);
        $fleetEvent = FleetTelemetryEvent::query()->create([
            'asset_id' => $vehicle->id,
            'asset_tracker_id' => null,
            'device_id' => $fleet->id,
            'vendor' => 'release_fixture',
            'occurred_at' => now()->subMinutes(4),
            'received_at' => now()->subMinutes(4),
            'latitude' => -40.9,
            'longitude' => 174.1,
            'event_type' => 'position',
            'idempotency_key' => hash('sha256', 'operational-fleet-position'),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });
        $before = $this->history($viewer, [$personal->id, $fleet->id]);

        $this->assertCount(2, $before);
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => preg_match('/\bfrom\s+[`"]?integration_events\b/', $sql) === 1,
        ));
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => preg_match('/\bfrom\s+[`"]?fleet_telemetry_events\b/', $sql) === 1,
        ));
        $this->assertSame(
            ['Operational fleet tracker', 'Synthetic personal tracker'],
            $before->pluck('deviceName')->sort()->values()->all(),
        );
        $this->actingAs($viewer)
            ->getJson("/operations/clients/{$client->id}/location/history")
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.lat', -41)
            ->assertJsonPath('locations.0.lng', 174);

        $consent->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
            'withdrawn_by_user_id' => $viewer->id,
            'withdrawal_reason' => 'Canonical privacy test',
            'updated_by' => $viewer->id,
        ]);

        $after = $this->history($viewer, [$personal->id, $fleet->id]);

        $this->assertCount(1, $after);
        $this->assertSame('Operational fleet tracker', $after->sole()['deviceName']);
        $this->assertTrue(IntegrationEvent::query()->whereKey($personalEvent->id)->exists());
        $this->assertTrue(FleetTelemetryEvent::query()->whereKey($fleetEvent->id)->exists());
        $this->actingAs($viewer)
            ->getJson("/operations/clients/{$client->id}/location/history")
            ->assertForbidden();
    }

    public function test_batched_history_reads_each_source_once_and_applies_one_global_cap(): void
    {
        $site = Site::factory()->create();
        $canonical = Device::factory()->tracking()->create(['name' => 'Canonical batch tracker']);
        $fleet = Device::factory()->tracking()->create([
            'name' => 'Fleet batch tracker',
            'category' => 'vehicle_tracker',
        ]);
        $excluded = Device::factory()->tracking()->create(['name' => 'Excluded tracker']);
        $asset = Asset::factory()->forSite($site)->create(['category' => 'Vehicle']);
        $base = now()->startOfSecond();
        $rows = collect(range(1, 105))->map(fn (int $index): array => IntegrationEvent::factory()->raw([
            'site_id' => $site->id,
            'room_id' => null,
            'hardware_id' => null,
            'canonical_device_id' => $canonical->id,
            'provider' => 'batch_history_test',
            'source_app' => 'batch_history_test',
            'source_event_id' => "batch-history-{$index}",
            'occurred_at' => $base->copy()->subSeconds($index),
            'received_at' => $base->copy()->subSeconds($index),
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => 'location_report',
            'tags' => json_encode(['synthetic' => true], JSON_THROW_ON_ERROR),
            'normalized_payload' => json_encode([
                'lat' => 0.01 + ($index / 100000),
                'lng' => 0.01 + ($index / 100000),
            ], JSON_THROW_ON_ERROR),
            'raw_payload' => null,
            'created_at' => $base,
            'updated_at' => $base,
        ]))->all();
        DB::table('integration_events')->insert($rows);
        IntegrationEvent::query()->create([
            'site_id' => $site->id,
            'canonical_device_id' => $excluded->id,
            'provider' => 'batch_history_test',
            'source_app' => 'batch_history_test',
            'source_event_id' => 'excluded-newest-history',
            'occurred_at' => $base->copy()->addMinute(),
            'received_at' => $base->copy()->addMinute(),
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => 'location_report',
            'tags' => ['synthetic' => true],
            'normalized_payload' => ['lat' => 9.0, 'lng' => 9.0],
            'raw_payload' => null,
        ]);
        FleetTelemetryEvent::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => null,
            'device_id' => $fleet->id,
            'vendor' => 'batch_history_test',
            'occurred_at' => $base->copy()->addSecond(),
            'received_at' => $base->copy()->addSecond(),
            'latitude' => 0.02,
            'longitude' => 0.02,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', 'batch-fleet-newest'),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $history = app(IntegrationEventHistoryService::class)->forDevices(
            collect([$canonical, $fleet]),
            includeEventType: true,
            retentionDays: 90,
            limit: 100,
        );
        $integrationQueries = collect($queries)->filter(
            fn (string $sql): bool => preg_match('/\bfrom\s+[`"]?integration_events\b/', $sql) === 1,
        );
        $fleetQueries = collect($queries)->filter(
            fn (string $sql): bool => preg_match('/\bfrom\s+[`"]?fleet_telemetry_events\b/', $sql) === 1,
        );

        $this->assertCount(100, $history);
        $this->assertCount(1, $integrationQueries);
        $this->assertCount(1, $fleetQueries);
        $this->assertSame($fleet->id, $history->first()['device_id']);
        $this->assertSame(
            [$canonical->id, $fleet->id],
            $history->pluck('device_id')->unique()->sort()->values()->all(),
        );
        $this->assertNotContains(9.0, $history->pluck('lat')->all());
    }

    public function test_batched_history_rejects_ambiguous_legacy_ids_but_keeps_unique_fallbacks(): void
    {
        $site = Site::factory()->create();
        $sharedAsset = Asset::factory()->forSite($site)->create(['category' => 'Vehicle']);
        $uniqueAsset = Asset::factory()->forSite($site)->create(['category' => 'Vehicle']);
        $sharedHardware = LocationHardware::query()->create([
            'site_id' => $site->id,
            'provider' => 'legacy_history_test',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Shared legacy hardware',
            'status' => LocationHardware::STATUS_ONLINE,
            'last_seen_at' => now(),
        ]);
        $uniqueHardware = LocationHardware::query()->create([
            'site_id' => $site->id,
            'provider' => 'legacy_history_test',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Unique legacy hardware',
            'status' => LocationHardware::STATUS_ONLINE,
            'last_seen_at' => now(),
        ]);
        $sharedTracker = AssetTracker::query()->create([
            'asset_id' => $sharedAsset->id,
            'vendor' => 'legacy_history_test',
            'device_uid' => 'SHARED-LEGACY-TRACKER',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $uniqueTracker = AssetTracker::query()->create([
            'asset_id' => $uniqueAsset->id,
            'vendor' => 'legacy_history_test',
            'device_uid' => 'UNIQUE-LEGACY-TRACKER',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $ambiguousA = Device::factory()->tracking()->create([
            'name' => 'Ambiguous legacy tracker A',
            'legacy_location_hardware_id' => $sharedHardware->id,
            'legacy_asset_tracker_id' => $sharedTracker->id,
        ]);
        $ambiguousB = Device::factory()->tracking()->create([
            'name' => 'Ambiguous legacy tracker B',
            'legacy_location_hardware_id' => $sharedHardware->id,
            'legacy_asset_tracker_id' => $sharedTracker->id,
        ]);
        $unique = Device::factory()->tracking()->create([
            'name' => 'Unique legacy tracker',
            'legacy_location_hardware_id' => $uniqueHardware->id,
            'legacy_asset_tracker_id' => $uniqueTracker->id,
        ]);
        $base = now()->startOfSecond();
        IntegrationEvent::query()->create([
            'site_id' => $site->id,
            'hardware_id' => $sharedHardware->id,
            'canonical_device_id' => null,
            'provider' => 'legacy_history_test',
            'source_app' => 'legacy_history_test',
            'source_event_id' => 'ambiguous-legacy-integration',
            'occurred_at' => $base->copy()->subSeconds(4),
            'received_at' => $base->copy()->subSeconds(4),
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => 'location_report',
            'normalized_payload' => ['lat' => 1.1, 'lng' => 1.1],
            'raw_payload' => null,
        ]);
        IntegrationEvent::query()->create([
            'site_id' => $site->id,
            'hardware_id' => $uniqueHardware->id,
            'canonical_device_id' => null,
            'provider' => 'legacy_history_test',
            'source_app' => 'legacy_history_test',
            'source_event_id' => 'unique-legacy-integration',
            'occurred_at' => $base->copy()->subSeconds(3),
            'received_at' => $base->copy()->subSeconds(3),
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => 'location_report',
            'normalized_payload' => ['lat' => 3.3, 'lng' => 3.3],
            'raw_payload' => null,
        ]);
        FleetTelemetryEvent::query()->create([
            'asset_id' => $sharedAsset->id,
            'asset_tracker_id' => $sharedTracker->id,
            'device_id' => null,
            'vendor' => 'legacy_history_test',
            'occurred_at' => $base->copy()->subSeconds(2),
            'received_at' => $base->copy()->subSeconds(2),
            'latitude' => 2.2,
            'longitude' => 2.2,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', 'ambiguous-legacy-fleet'),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);
        FleetTelemetryEvent::query()->create([
            'asset_id' => $uniqueAsset->id,
            'asset_tracker_id' => $uniqueTracker->id,
            'device_id' => null,
            'vendor' => 'legacy_history_test',
            'occurred_at' => $base->copy()->subSeconds(1),
            'received_at' => $base->copy()->subSeconds(1),
            'latitude' => 4.4,
            'longitude' => 4.4,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', 'unique-legacy-fleet'),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);

        $history = app(IntegrationEventHistoryService::class)->forDevices(
            collect([$ambiguousA, $ambiguousB, $unique]),
            includeEventType: true,
            retentionDays: 90,
        );

        $this->assertCount(2, $history);
        $this->assertSame([$unique->id], $history->pluck('device_id')->unique()->values()->all());
        $this->assertSame([3.3, 4.4], $history->pluck('lat')->sort()->values()->all());
        $this->assertNotContains(1.1, $history->pluck('lat')->all());
        $this->assertNotContains(2.2, $history->pluck('lat')->all());
    }

    /** @param list<int> $deviceIds */
    private function history(User $viewer, array $deviceIds): Collection
    {
        $payload = app(TrackingWorkspacePresenter::class)->present(
            $viewer,
            Device::query()->whereIn('id', $deviceIds),
            [
                'key' => 'history',
                'label' => 'History',
                'description' => 'Canonical retained tracking history.',
            ],
        );

        return collect($payload['activeTab']['history']);
    }
}
