<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use App\Services\Integration\UnifiOperationalBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * PR P Phase 2: the non-UniFi branch of PullIntegrationHealthJob now writes to
 * the canonical Device model (NOT LocationHardware).
 *
 * Mirrors UnifiOperationalBridgeMigrationTest::test_pull_health_job_updates_canonical_device_first_for_unifi
 * but exercises the `hikvision` provider path — the non-UniFi branch that used
 * to call LocationHardware::find()->update() directly.
 */
class NonUnifiHealthPullMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hikvision_health_pull_updates_canonical_device_not_location_hardware(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Hik Site']);
        $siteConfig = $this->makeSiteConfig($site, 'hikvision');
        $tenantSecret = $this->makeTenantSecret('hikvision');

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'hikvision',
            'category' => LocationHardware::CATEGORY_CAMERA,
            'name' => 'Hik Camera',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'hik-cam-1'],
        ]);
        $originalShadowUpdatedAt = $shadow->updated_at;

        $device = Device::factory()->security()->create([
            'tenant_id' => 1,
            'provider' => 'hikvision',
            'name' => 'Hik Camera',
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'hik-cam-1'],
        ]);

        $adapter = $this->makeFakeAdapter('hikvision', [[
            'provider_entity_id' => 'hik-cam-1',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(15)->toIso8601String(),
        ]]);

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('resolve')->once()->with('hikvision')->andReturn($adapter);

        $job = new PullIntegrationHealthJob(1, 'hikvision', $site->id);
        $job->handle($registry, app(UnifiOperationalBridgeService::class));

        $device->refresh();
        $shadow->refresh();

        // Canonical Device is updated.
        $this->assertSame(DeviceStatus::Offline, $device->status);
        $this->assertSame(HealthStatus::Critical, $device->health_status);
        $this->assertNotNull($device->last_seen_at);

        // Legacy shadow is NOT touched.
        $this->assertSame(LocationHardware::STATUS_ONLINE, $shadow->status);
        $this->assertEquals($originalShadowUpdatedAt, $shadow->updated_at);
    }

    public function test_iot_health_pull_resolves_via_legacy_hardware_id_fallback(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'IoT Site']);
        $siteConfig = $this->makeSiteConfig($site, 'iot');
        $tenantSecret = $this->makeTenantSecret('iot');

        // This legacy shadow exists but the canonical Device has no provider_entity_id
        // in external_ref — the only link is legacy_location_hardware_id. The
        // fallback branch of resolveCanonicalDevice() must find it by hardware_id.
        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'iot',
            'category' => LocationHardware::CATEGORY_SENSOR,
            'name' => 'Fridge Sensor',
            'status' => LocationHardware::STATUS_ONLINE,
        ]);
        $originalShadowUpdatedAt = $shadow->updated_at;

        $device = Device::factory()->facilities()->create([
            'tenant_id' => 1,
            'provider' => 'iot',
            'name' => 'Fridge Sensor',
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => [],
        ]);

        $adapter = $this->makeFakeAdapter('iot', [[
            'hardware_id' => $shadow->id,
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(2)->toIso8601String(),
        ]]);

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('resolve')->once()->with('iot')->andReturn($adapter);

        $job = new PullIntegrationHealthJob(1, 'iot', $site->id);
        $job->handle($registry, app(UnifiOperationalBridgeService::class));

        $device->refresh();
        $shadow->refresh();

        $this->assertSame(DeviceStatus::Offline, $device->status);
        $this->assertSame(HealthStatus::Critical, $device->health_status);

        // Shadow still untouched.
        $this->assertSame(LocationHardware::STATUS_ONLINE, $shadow->status);
        $this->assertEquals($originalShadowUpdatedAt, $shadow->updated_at);
    }

    public function test_entry_without_resolvable_device_is_counted_as_errored_not_thrown(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Orphan Site']);
        $siteConfig = $this->makeSiteConfig($site, 'hikvision');
        $tenantSecret = $this->makeTenantSecret('hikvision');

        // Adapter returns an entry that won't match anything.
        $adapter = $this->makeFakeAdapter('hikvision', [[
            'provider_entity_id' => 'does-not-exist',
            'status' => 'offline',
        ]]);

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('resolve')->once()->with('hikvision')->andReturn($adapter);

        $job = new PullIntegrationHealthJob(1, 'hikvision', $site->id);
        $job->handle($registry, app(UnifiOperationalBridgeService::class));

        // Must not have thrown. No devices exist to assert against, but no
        // LocationHardware row should have been created either.
        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, LocationHardware::query()->count());
    }

    private function makeSiteConfig(Site $site, string $provider): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => $provider,
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'site-ext-1',
            'mapped_external_site_name' => 'External Site',
            'is_active' => true,
        ]);
    }

    private function makeTenantSecret(string $provider): IntegrationTenantSecret
    {
        return IntegrationTenantSecret::create([
            'tenant_id' => 1,
            'provider' => $provider,
            'secret_encrypted' => Crypt::encryptString('test-key'),
            'secret_last4' => '1234',
            'status' => IntegrationTenantSecret::STATUS_CONNECTED,
        ]);
    }

    private function makeFakeAdapter(string $provider, array $healthResults): IntegrationAdapterInterface
    {
        return new class($provider, $healthResults) implements IntegrationAdapterInterface
        {
            public function __construct(private string $providerSlug, private array $healthResults) {}

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
                return new SyncResult;
            }

            public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): array
            {
                return $this->healthResults;
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
                return $this->providerSlug;
            }
        };
    }
}
