<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Models\AuditLog;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\UnifiOperationalBridgeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class UnifiOperationalBridgeMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unifi_sync_creates_canonical_device_without_legacy_shadow(): void
    {
        // PR P Phase 1: UnifiOperationalBridgeService no longer writes to the
        // legacy `location_hardware` table. The canonical Device + DeviceAssignment
        // are the sole source of truth after a sync; provenance is carried via
        // integration_events.canonical_device_id.
        $site = Site::factory()->create(['name' => 'North Hub']);
        $siteConfig = $this->makeSiteConfig($site);
        $providerConnection = $this->makeProviderConnection();

        $this->fakeUnifiInventory([
            [
                'id' => 'unifi-ap-1',
                'productLine' => 'network',
                'shortname' => 'uap',
                'model' => 'U6-LR',
                'serial' => 'UNIFI-001',
                'mac' => 'AA:BB:CC:DD:EE:01',
                'status' => 'online',
                'ip' => '192.168.10.15',
                'version' => '7.0.23',
                'name' => 'Lobby AP',
                'lastSeen' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);

        $result = app(UnifiAdapter::class)->syncDevices($siteConfig, $providerConnection);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->updated);

        $device = Device::query()->where('provider', 'unifi')->firstOrFail();
        $assignment = $device->assignments()->active()->first();

        $this->assertSame('Lobby AP', $device->name);
        $this->assertSame('it_infrastructure', $device->domain);
        $this->assertSame('network', $device->category);
        $this->assertSame('wireless_ap', $device->subcategory);
        $this->assertSame('Ubiquiti', $device->manufacturer);
        $this->assertSame('unifi-ap-1', $device->external_ref['provider_entity_id']);
        $this->assertSame(DeviceStatus::Active, $device->status);
        $this->assertSame(HealthStatus::Healthy, $device->health_status);

        $this->assertNotNull($assignment);
        $this->assertSame('site', $assignment->assignable_type);
        $this->assertSame($site->id, $assignment->assignable_id);

        // Phase 1: no legacy shadow is created and the device is not linked to one.
        $this->assertNull($device->legacy_location_hardware_id);
        $this->assertSame(0, LocationHardware::query()->count());
    }

    public function test_unifi_access_events_use_exact_site_credentials(): void
    {
        CarbonImmutable::setTestNow('2026-08-03T10:15:00Z');
        $site = Site::factory()->create([]);
        $siteConfig = $this->makeSiteConfig($site);
        $siteConfig->update([]);
        $providerConnection = $this->makeProviderConnection();
        IntegrationSiteSecret::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => 'access_api',
            'base_url' => 'https://access.example.test',
            'secret_encrypted' => Crypt::encryptString('site-access-key'),
            'is_enabled' => true,
        ]);
        Http::fake([
            'https://access.example.test/api/v1/developer/system/logs*' => Http::response([
                'code' => 'SUCCESS',
                'data' => [
                    'hits' => [[
                        '@timestamp' => '2026-08-03T10:10:00Z',
                        '_id' => 'door-event-1',
                        '_source' => [
                            'actor' => ['display_name' => 'Aroha', 'type' => 'user'],
                            'event' => [
                                'display_message' => 'Access Granted',
                                'published' => CarbonImmutable::parse('2026-08-03T10:10:00Z')->valueOf(),
                                'result' => 'ACCESS',
                                'type' => 'access.door.unlock',
                            ],
                            'target' => [[
                                'display_name' => 'Front door',
                                'id' => 'door-1',
                                'type' => 'door',
                            ]],
                        ],
                    ]],
                    'page' => 1,
                    'total' => 1,
                ],
            ]),
        ]);

        $registry = app(IntegrationAdapterRegistry::class);
        $events = app(UnifiAdapter::class)->collectEvents($siteConfig, $providerConnection, null, 25);

        $this->assertTrue($registry->hasCapability('unifi', EventCollectionCapability::class));
        $this->assertCount(1, $events->items);
        $this->assertSame(
            'access-log-'.hash('sha256', $site->id.'|door-event-1'),
            $events->items[0]['source_event_id'],
        );
        $this->assertSame($site->id, $events->items[0]['site_id']);
        $this->assertSame('Access Granted', $events->items[0]['normalized_payload']['summary']);
        $this->assertSame('Front door', $events->items[0]['normalized_payload']['door_name']);
        $this->assertSame('2026-08-03T10:15:00+00:00', $events->nextCursor);
        $this->assertArrayNotHasKey('raw', $events->items[0]);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://access.example.test/api/v1/developer/system/logs?page_size=25&page_num=1'
            && $request->hasHeader('Authorization', 'Bearer site-access-key')
            && $request['topic'] === 'door_openings'
            && $request['since'] === CarbonImmutable::parse('2026-08-01T10:15:00Z')->timestamp
            && $request['until'] === CarbonImmutable::parse('2026-08-03T10:15:00Z')->timestamp);
    }

    public function test_unifi_sync_preserves_existing_room_assignment_within_same_site(): void
    {
        $site = Site::factory()->create(['name' => 'South Hub']);
        $room = SiteRoom::create([
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);

        $siteConfig = $this->makeSiteConfig($site);
        $providerConnection = $this->makeProviderConnection();

        // Pre-existing legacy shadow from before PR P Phase 1. After Phase 1 the
        // sync must NOT write to this row; it is read-only historical data.
        $shadow = LocationHardware::create([
            'site_id' => $site->id,
            'room_id' => $room->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_SWITCH,
            'name' => 'Core Switch',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'switch-1'],
        ]);
        $originalShadowUpdatedAt = $shadow->updated_at;

        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'Old Switch',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'switch-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now()->subDay(),
        ]);

        $this->fakeUnifiInventory([
            [
                'id' => 'switch-1',
                'productLine' => 'network',
                'shortname' => 'usw',
                'model' => 'USW-Pro-24',
                'serial' => 'SW-001',
                'mac' => 'AA:BB:CC:DD:EE:02',
                'status' => 'online',
                'ip' => '192.168.10.20',
                'version' => '7.0.23',
                'name' => 'Core Switch',
                'lastSeen' => now()->subMinutes(2)->toIso8601String(),
            ],
        ]);

        $result = app(UnifiAdapter::class)->syncDevices($siteConfig, $providerConnection);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);

        $device->refresh();
        $shadow->refresh();
        $assignment = $device->assignments()->active()->first();

        $this->assertSame('Core Switch', $device->name);
        $this->assertNotNull($assignment);
        $this->assertSame('room', $assignment->assignable_type);
        $this->assertSame($room->id, $assignment->assignable_id);

        // Phase 1: the pre-existing shadow must be untouched — its timestamp
        // should not have moved and its fields should still reflect the
        // original seeded values.
        $this->assertEquals($originalShadowUpdatedAt, $shadow->updated_at);
        $this->assertSame($room->id, $shadow->room_id);
        $this->assertSame(LocationHardware::CATEGORY_SWITCH, $shadow->category);
        $this->assertSame(LocationHardware::STATUS_ONLINE, $shadow->status);
    }

    public function test_unifi_sync_never_relocates_a_matching_device_from_another_site(): void
    {
        $sourceSite = Site::factory()->create(['name' => 'Source Site']);
        $mappedSite = Site::factory()->create(['name' => 'Mapped Site']);
        $siteConfig = $this->makeSiteConfig($mappedSite);
        $providerConnection = $this->makeProviderConnection();
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'Protected source switch',
            'status' => DeviceStatus::Active,
            'external_ref' => ['provider_entity_id' => 'cross-site-switch'],
        ]);
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $sourceSite->id,
            'assigned_at' => now(),
        ]);
        $before = [
            'device' => $device->fresh()->getAttributes(),
            'assignments' => $device->assignments()->orderBy('id')->get()->map->getAttributes()->all(),
        ];

        $this->fakeUnifiInventory([[
            'id' => 'cross-site-switch',
            'productLine' => 'network',
            'shortname' => 'usw',
            'model' => 'USW-Pro-24',
            'serial' => 'CROSS-SITE-001',
            'mac' => 'AA:BB:CC:DD:EE:91',
            'status' => 'offline',
            'name' => 'Attempted relocation',
        ]]);

        $result = app(UnifiAdapter::class)->syncDevices($siteConfig, $providerConnection);

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->errored);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame($before, [
            'device' => $device->fresh()->getAttributes(),
            'assignments' => $device->assignments()->orderBy('id')->get()->map->getAttributes()->all(),
        ]);
        $this->assertNull($assignment->fresh()->released_at);
        $this->assertSame(1, Device::query()->where('external_ref->provider_entity_id', 'cross-site-switch')->count());
    }

    public function test_pull_health_job_refuses_unadvertised_facade_health_for_unifi(): void
    {
        $site = Site::factory()->create(['name' => 'Health Site']);
        $siteConfig = $this->makeSiteConfig($site);
        $providerConnection = $this->makeProviderConnection();
        $providerConnection->update(['status' => IntegrationProviderConnection::STATUS_CONNECTED]);

        $shadow = LocationHardware::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Health AP',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'health-ap-1'],
        ]);
        $originalShadowUpdatedAt = $shadow->updated_at;

        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'Health AP',
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'health-ap-1'],
        ]);
        $originalLastSeenAt = $device->last_seen_at;

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('unifi', ObservationCollectionCapability::class)
            ->andReturnFalse();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('unifi', EventCollectionCapability::class)
            ->andReturnFalse();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('unifi', SnapshotCollectionCapability::class)
            ->andReturnFalse();

        $job = new PullIntegrationHealthJob('unifi', $site->id);
        $job->handle($registry);

        $device->refresh();
        $shadow->refresh();

        $this->assertSame(DeviceStatus::Active, $device->status);
        $this->assertSame(HealthStatus::Healthy, $device->health_status);
        $this->assertEquals($originalLastSeenAt, $device->last_seen_at);
        $this->assertDatabaseCount('integration_sync_logs', 0);

        // Phase 1 (PR P): the legacy shadow must NOT be updated by a UniFi
        // health sync. Its status and updated_at should still match the seeded
        // values.
        $this->assertSame(LocationHardware::STATUS_ONLINE, $shadow->status);
        $this->assertEquals($originalShadowUpdatedAt, $shadow->updated_at);
    }

    public function test_unifi_health_from_one_site_cannot_update_a_device_at_another_site(): void
    {
        $mappedSite = Site::factory()->create([]);
        $otherSite = Site::factory()->create([]);
        $siteConfig = $this->makeSiteConfig($mappedSite);
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'external_ref' => ['provider_entity_id' => 'other-site-ap'],
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $otherSite->id,
            'assigned_at' => now(),
        ]);

        $updated = app(UnifiOperationalBridgeService::class)->applyHealthUpdate($siteConfig, [
            'device_id' => $device->id,
            'provider_entity_id' => 'other-site-ap',
            'status' => 'offline',
            'last_seen_at' => now()->toIso8601String(),
        ]);

        $this->assertFalse($updated);
        $this->assertSame(DeviceStatus::Active, $device->fresh()->status);
        $this->assertSame(HealthStatus::Healthy, $device->fresh()->health_status);
    }

    public function test_room_assignment_revalidates_fresh_current_provenance_before_non_null_replacement(): void
    {
        $localSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $originalRoom = SiteRoom::create([
            'site_id' => $localSite->id,
            'name' => 'Original local room',
        ]);
        $targetRoom = SiteRoom::create([
            'site_id' => $localSite->id,
            'name' => 'Target local room',
        ]);
        $contradictoryRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'Contradictory current room',
        ]);
        $shadow = LocationHardware::create([
            'site_id' => $localSite->id,
            'room_id' => $originalRoom->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Local historical shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'stale-room-device'],
        ]);
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'stale-room-device'],
        ]);
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $originalRoom->id,
            'assigned_at' => now(),
        ]);

        $staleDevice = $device->fresh();
        $assignment->update(['assignable_id' => $contradictoryRoom->id]);
        $before = [
            'device' => $device->fresh()->getAttributes(),
            'hardware' => $shadow->fresh()->getAttributes(),
            'assignments' => DeviceAssignment::query()->where('device_id', $device->id)->get()->map->getAttributes()->all(),
            'audits' => AuditLog::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];

        $caught = null;
        try {
            app(UnifiOperationalBridgeService::class)->syncRoomAssignment(
                $staleDevice,
                $targetRoom,
                null,
                $localSite->id,
            );
        } catch (NotFoundHttpException $exception) {
            $caught = $exception::class;
        }

        $this->assertSame([
            'exception' => NotFoundHttpException::class,
            'state_unchanged' => true,
        ], [
            'exception' => $caught,
            'state_unchanged' => $before === [
                'device' => $device->fresh()->getAttributes(),
                'hardware' => $shadow->fresh()->getAttributes(),
                'assignments' => DeviceAssignment::query()->where('device_id', $device->id)->get()->map->getAttributes()->all(),
                'audits' => AuditLog::query()->orderBy('id')->get()->map->getAttributes()->all(),
            ],
        ]);
    }

    private function makeSiteConfig(Site $site): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'site-ext-1',
            'mapped_external_site_name' => 'UniFi Site',
            'is_active' => true,
        ]);
    }

    private function makeProviderConnection(): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('test-unifi-key'),
            'secret_last4' => 'fifi',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    private function fakeUnifiInventory(array $devices): void
    {
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response([
                'data' => [[
                    'siteId' => 'site-ext-1',
                    'hostId' => 'host-1',
                ]],
            ], 200),
            'https://api.ui.com/v1/devices' => Http::response([
                'data' => [[
                    'hostId' => 'host-1',
                    'devices' => $devices,
                ]],
            ], 200),
            '*' => Http::response([], 404),
        ]);
    }
}
