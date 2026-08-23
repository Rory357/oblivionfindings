<?php

namespace Tests\Feature\Sites;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\Data\IntegrationCapabilityManifest;
use App\Services\Integration\Data\ProviderEventPage;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use App\Support\SafeOperationalData;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SiteIntegrationMutationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public const PROVIDER = 'sentinel-provider';

    public const RAW_FAILURE = 'Bearer RAW-PROVIDER-SECRET at https://provider.invalid/private?token=RAW-TOKEN';

    private User $manager;

    private Site $site;

    private IntegrationProviderConnection $providerConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->manager = User::factory()->create(['approved_at' => now()]);
        $this->manager->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->providerConnection = IntegrationProviderConnection::create([
            'provider' => self::PROVIDER,
            'secret_encrypted' => 'encrypted-at-rest',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        app(IntegrationAdapterRegistry::class)->register(
            self::PROVIDER,
            SentinelFailureAdapter::class,
            new IntegrationCapabilityManifest(
                provider: self::PROVIDER,
                version: '1.0',
                capabilities: [
                    InventoryDiscoveryCapability::class,
                    DeviceSyncCapability::class,
                    EventCollectionCapability::class,
                ],
                requiredPermissions: ['securityDevices.integrations.manage'],
                sensitivityLabels: ['provider_credentials', 'event_metadata'],
                pageLimit: 100,
                minimumIntervalSeconds: 60,
                backfillLimit: 100,
            ),
        );
    }

    public function test_site_sync_mutations_never_persist_flash_or_log_raw_provider_failures(): void
    {
        Log::spy();

        SentinelFailureAdapter::$operation = 'discover';
        $this->actingAs($this->manager)
            ->from("/sites/{$this->site->id}")
            ->post("/sites/{$this->site->id}/integrations/".self::PROVIDER.'/sync-sites')
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => ! str_contains($message, self::RAW_FAILURE));

        $this->assertSame(
            SafeOperationalData::failureSummary(),
            IntegrationSyncLog::query()->latest('id')->value('error_message'),
        );
        $this->assertSame(SafeOperationalData::failureSummary(), $this->providerConnection->fresh()->last_error);

        IntegrationSiteConfig::create([
            'site_id' => $this->site->id,
            'provider' => self::PROVIDER,
            'mapped_external_site_id' => 'mapped-site',
            'is_active' => true,
        ]);

        SentinelFailureAdapter::$operation = 'result';
        $this->actingAs($this->manager)
            ->from("/sites/{$this->site->id}")
            ->post("/sites/{$this->site->id}/integrations/".self::PROVIDER.'/sync-devices')
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => ! str_contains($message, self::RAW_FAILURE));

        $this->assertSame(
            SafeOperationalData::failureSummary(),
            IntegrationSyncLog::query()->latest('id')->value('error_message'),
        );
        $this->assertSame(SafeOperationalData::failureSummary(), $this->providerConnection->fresh()->last_error);

        SentinelFailureAdapter::$operation = 'sync-exception';
        $this->actingAs($this->manager)
            ->from("/sites/{$this->site->id}")
            ->post("/sites/{$this->site->id}/integrations/".self::PROVIDER.'/sync-devices')
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => ! str_contains($message, self::RAW_FAILURE));

        IntegrationSiteSecret::create([
            'site_id' => $this->site->id,
            'provider' => self::PROVIDER,
            'capability' => 'access_api',
            'base_url' => 'https://bounded.invalid',
            'secret_encrypted' => 'encrypted-site-secret',
            'is_enabled' => true,
        ]);
        $this->providerConnection->refresh()->update(['status' => IntegrationProviderConnection::STATUS_CONNECTED]);

        SentinelFailureAdapter::$operation = 'events';
        $this->actingAs($this->manager)
            ->from("/sites/{$this->site->id}")
            ->post("/sites/{$this->site->id}/integrations/".self::PROVIDER.'/pull-events')
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => ! str_contains($message, self::RAW_FAILURE));

        $this->assertSame(
            SafeOperationalData::failureSummary(),
            IntegrationSiteSecret::query()->where('provider', self::PROVIDER)->value('last_error'),
        );

        $databaseEvidence = json_encode([
            IntegrationSyncLog::query()->pluck('error_message')->all(),
            IntegrationProviderConnection::query()->pluck('last_error')->all(),
            IntegrationSiteSecret::query()->pluck('last_error')->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::RAW_FAILURE, $databaseEvidence);

        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function (string $message, array $context): bool {
            return ! str_contains($message, self::RAW_FAILURE)
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), self::RAW_FAILURE)
                && ($context['failure_category'] ?? null) === 'provider_failure';
        });
    }

    public function test_provider_site_identity_is_canonicalised_without_losing_a_zero_identifier(): void
    {
        $route = "/sites/{$this->site->id}/integrations/".self::PROVIDER;

        $this->actingAs($this->manager)->post($route, [
            'mapped_external_site_id' => '  mapped-site  ',
        ])->assertRedirect();

        $mapping = IntegrationSiteConfig::query()
            ->where('site_id', $this->site->id)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();
        $this->assertSame('mapped-site', $mapping->mapped_external_site_id);
        $this->assertSame(IntegrationSiteConfig::STATUS_HYBRID, $mapping->status);
        $this->assertTrue($mapping->is_active);

        $this->actingAs($this->manager)->post($route, [
            'mapped_external_site_id' => '   ',
        ])->assertRedirect();

        $mapping->refresh();
        $this->assertNull($mapping->mapped_external_site_id);
        $this->assertSame(IntegrationSiteConfig::STATUS_LOCAL_ONLY, $mapping->status);
        $this->assertFalse($mapping->is_active);

        $this->actingAs($this->manager)->post($route, [
            'mapped_external_site_id' => '0',
        ])->assertRedirect();

        $mapping->refresh();
        $this->assertSame('0', $mapping->mapped_external_site_id);
        $this->assertSame(IntegrationSiteConfig::STATUS_HYBRID, $mapping->status);
        $this->assertTrue($mapping->is_active);
    }

    public function test_unknown_provider_cannot_create_an_inert_site_mapping(): void
    {
        $this->actingAs($this->manager)->post(
            "/sites/{$this->site->id}/integrations/unregistered-provider",
            ['mapped_external_site_id' => 'external-site'],
        )->assertNotFound();

        $this->assertDatabaseCount('integration_site_configs', 0);
    }
}

final class SentinelFailureAdapter implements DeviceSyncCapability, EventCollectionCapability, IntegrationAdapterInterface, InventoryDiscoveryCapability
{
    public static string $operation = 'discover';

    public function testConnection(IntegrationProviderConnection $secret): bool
    {
        return false;
    }

    public function discoverSites(IntegrationProviderConnection $secret): array
    {
        throw new \RuntimeException(SiteIntegrationMutationSafetyTest::RAW_FAILURE);
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        if (self::$operation === 'result') {
            return new SyncResult(error: SiteIntegrationMutationSafetyTest::RAW_FAILURE);
        }

        throw new \RuntimeException(SiteIntegrationMutationSafetyTest::RAW_FAILURE);
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array
    {
        throw new \RuntimeException(SiteIntegrationMutationSafetyTest::RAW_FAILURE);
    }

    public function collectEvents(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderEventPage {
        throw new \RuntimeException(SiteIntegrationMutationSafetyTest::RAW_FAILURE);
    }

    public function capabilities(): array
    {
        return [
            InventoryDiscoveryCapability::class,
            DeviceSyncCapability::class,
            EventCollectionCapability::class,
        ];
    }

    public function provider(): string
    {
        return SiteIntegrationMutationSafetyTest::PROVIDER;
    }
}
