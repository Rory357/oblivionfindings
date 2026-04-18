<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use App\Services\Integration\UnifiOperationalBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnifiOperationalBridgeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unifi_sync_creates_canonical_device_without_legacy_shadow(): void
    {
        // PR P Phase 1: UnifiOperationalBridgeService no longer writes to the
        // legacy `location_hardware` table. The canonical Device + DeviceAssignment
        // are the sole source of truth after a sync; provenance is carried via
        // integration_events.canonical_device_id.
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'North Hub']);
        $siteConfig = $this->makeSiteConfig($site);
        $tenantSecret = $this->makeTenantSecret();

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

        $result = app(UnifiAdapter::class)->syncDevices($siteConfig, $tenantSecret);

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

    public function test_unifi_sync_preserves_existing_room_assignment_within_same_site(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'South Hub']);
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);

        $siteConfig = $this->makeSiteConfig($site);
        $tenantSecret = $this->makeTenantSecret();

        // Pre-existing legacy shadow from before PR P Phase 1. After Phase 1 the
        // sync must NOT write to this row; it is read-only historical data.
        $shadow = LocationHardware::create([
            'tenant_id' => 1,
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
            'tenant_id' => 1,
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

        $result = app(UnifiAdapter::class)->syncDevices($siteConfig, $tenantSecret);

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

    public function test_pull_health_job_updates_canonical_device_first_for_unifi(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Health Site']);
        $siteConfig = $this->makeSiteConfig($site);
        $tenantSecret = $this->makeTenantSecret();
        $tenantSecret->update(['status' => IntegrationTenantSecret::STATUS_CONNECTED]);

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Health AP',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'health-ap-1'],
        ]);
        $originalShadowUpdatedAt = $shadow->updated_at;

        $device = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'name' => 'Health AP',
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'health-ap-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $adapter = new class implements IntegrationAdapterInterface
        {
            public function testConnection(IntegrationTenantSecret $secret): bool
            {
                return true;
            }

            public function discoverSites(IntegrationTenantSecret $secret): array
            {
                return [];
            }

            public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): SyncResult
            {
                return new SyncResult();
            }

            public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): array
            {
                return [[
                    'provider_entity_id' => 'health-ap-1',
                    'status' => 'offline',
                    'last_seen_at' => now()->subMinutes(10)->toIso8601String(),
                ]];
            }

            public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret, ?\DateTimeInterface $since = null): array
            {
                return [];
            }

            public function capabilities(): array
            {
                return ['device_health'];
            }

            public function provider(): string
            {
                return 'unifi';
            }
        };

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('resolve')
            ->once()
            ->with('unifi')
            ->andReturn($adapter);

        $job = new PullIntegrationHealthJob(1, 'unifi', $site->id);
        $job->handle($registry, app(UnifiOperationalBridgeService::class));

        $device->refresh();
        $shadow->refresh();

        $this->assertSame(DeviceStatus::Offline, $device->status);
        $this->assertSame(HealthStatus::Critical, $device->health_status);
        $this->assertNotNull($device->last_seen_at);

        // Phase 1 (PR P): the legacy shadow must NOT be updated by a UniFi
        // health sync. Its status and updated_at should still match the seeded
        // values.
        $this->assertSame(LocationHardware::STATUS_ONLINE, $shadow->status);
        $this->assertEquals($originalShadowUpdatedAt, $shadow->updated_at);
    }

    private function makeSiteConfig(Site $site): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'site-ext-1',
            'mapped_external_site_name' => 'UniFi Site',
            'is_active' => true,
        ]);
    }

    private function makeTenantSecret(): IntegrationTenantSecret
    {
        return IntegrationTenantSecret::create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('test-unifi-key'),
            'secret_last4' => 'fifi',
            'status' => IntegrationTenantSecret::STATUS_CONNECTED,
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
