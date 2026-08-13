<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\LocationHardware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use App\Services\Integration\SyncResult;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\FakeIntegrationSecretBackend;
use Tests\TestCase;

class UnifiSettingsRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $backend = new FakeIntegrationSecretBackend;
        $this->app->instance(SecretManagerSecretStore::class, $backend);
        $this->app->instance(SecretManagerLeaseIssuer::class, $backend);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->get('/security-devices/integrations/unifi')->assertRedirect('/login');
    }

    public function test_requires_integration_permission(): void
    {
        $this->actingAs($this->noPerms)
            ->get('/security-devices/integrations/unifi')
            ->assertForbidden();
    }

    public function test_accessible_with_permission(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi')
            ->assertOk();
    }

    public function test_disable_requires_the_exact_integration_management_permission(): void
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewAny = Permission::query()->where('key', 'securityDevices.viewAny')->firstOrFail();
        $viewer->permissionOverrides()->attach($viewAny->id, ['allowed' => true]);
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('permission-test-key'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $this->actingAs($viewer)
            ->post('/security-devices/integrations/unifi/disable', [
                'reason' => 'provider_outage',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas((new IntegrationProviderConnection)->getTable(), [
            'provider' => 'unifi',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'disabled_at' => null,
        ]);
    }

    public function test_disable_requires_a_governed_reason(): void
    {
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('reason-test-key'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/disable')
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/disable', [
                'reason' => 'free text that is not an approved reason',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas((new IntegrationProviderConnection)->getTable(), [
            'provider' => 'unifi',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'requires_credential_replacement' => false,
        ]);
    }

    public function test_disable_is_audited_fail_closed_and_preserves_provider_history_and_cursors(): void
    {
        $site = Site::factory()->create();
        $lastTestedAt = now()->subHours(3)->startOfSecond();
        $lastSyncedAt = now()->subHours(2)->startOfSecond();
        $encryptedSecret = Crypt::encryptString('existing-unifi-key');
        $connection = IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => $encryptedSecret,
            'secret_last4' => '-key',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_tested_at' => $lastTestedAt,
            'last_synced_at' => $lastSyncedAt,
            'last_error' => 'Existing bounded failure evidence.',
            'config' => ['discovered_sites' => [['external_id' => 'preserved-site']]],
        ]);
        Integration::create([
            'provider' => 'unifi',
            'display_name' => 'UniFi',
            'status' => Integration::STATUS_ACTIVE,
        ]);
        $siteConfig = IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'preserved-site',
            'is_active' => true,
        ]);
        $cursor = ProviderCapabilityCursor::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => 'event_collection',
            'cursor' => 'preserved-cursor',
            'last_completed_at' => now()->subMinute(),
        ]);
        $syncLog = IntegrationSyncLog::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'action' => 'pull_events',
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);
        $event = IntegrationEvent::factory()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
        ]);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/disable', [
                'reason' => 'provider_outage',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $connection->fresh();
        $this->assertSame(IntegrationProviderConnection::STATUS_DISABLED, $fresh->status);
        $this->assertTrue($fresh->requires_credential_replacement);
        $this->assertSame('provider_outage', $fresh->disabled_reason);
        $this->assertSame($this->admin->id, $fresh->disabled_by);
        $this->assertNotNull($fresh->disabled_at);
        $this->assertSame('existing-unifi-key', Crypt::decryptString($fresh->secret_encrypted));
        $this->assertSame($lastTestedAt->toDateTimeString(), $fresh->last_tested_at?->toDateTimeString());
        $this->assertSame($lastSyncedAt->toDateTimeString(), $fresh->last_synced_at?->toDateTimeString());
        $this->assertSame('Existing bounded failure evidence.', $fresh->last_error);
        $this->assertSame('preserved-site', data_get($fresh->config, 'discovered_sites.0.external_id'));
        $this->assertFalse(IntegrationProviderConnection::query()->forProvider('unifi')->connected()->exists());
        $this->assertSame(Integration::STATUS_INACTIVE, Integration::query()->where('provider', 'unifi')->value('status'));
        $this->assertTrue(IntegrationSiteConfig::query()->whereKey($siteConfig->id)->exists());
        $this->assertSame('preserved-cursor', ProviderCapabilityCursor::query()->findOrFail($cursor->id)->cursor);
        $this->assertTrue(IntegrationSyncLog::query()->whereKey($syncLog->id)->exists());
        $this->assertTrue(IntegrationEvent::query()->whereKey($event->id)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'securityDevices.integration.unifi.disabled.provider_outage',
            'auditable_type' => IntegrationProviderConnection::class,
            'auditable_id' => $connection->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providerConnection.status', IntegrationProviderConnection::STATUS_DISABLED)
                ->where('providerConnection.disabled_reason', 'provider_outage')
                ->where('providerConnection.requires_credential_replacement', true));
    }

    public function test_disabled_connection_cannot_be_tested_or_collect_inventory_through_manual_paths(): void
    {
        $site = Site::factory()->create();
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('disabled-unifi-key'),
            'status' => IntegrationProviderConnection::STATUS_DISABLED,
            'requires_credential_replacement' => true,
        ]);
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldNotReceive('hasCapability');
        $registry->shouldNotReceive('capability');
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/test')
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/sync-sites')
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAs($this->admin)
            ->post("/sites/{$site->id}/integrations/unifi/test")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAs($this->admin)
            ->post("/sites/{$site->id}/integrations/unifi/sync-sites")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('integration_sync_logs', 0);
        $this->assertDatabaseHas((new IntegrationProviderConnection)->getTable(), [
            'provider' => 'unifi',
            'status' => IntegrationProviderConnection::STATUS_DISABLED,
            'requires_credential_replacement' => true,
        ]);
    }

    public function test_recovery_requires_a_replacement_key_then_a_successful_connection_test(): void
    {
        $connection = IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('disabled-old-key'),
            'secret_last4' => '-key',
            'status' => IntegrationProviderConnection::STATUS_DISABLED,
            'disabled_at' => now()->subHour(),
            'disabled_by' => $this->admin->id,
            'disabled_reason' => 'security_review',
            'requires_credential_replacement' => true,
        ]);
        Integration::create([
            'provider' => 'unifi',
            'display_name' => 'UniFi',
            'status' => Integration::STATUS_INACTIVE,
        ]);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/rotate', [
                'api_key' => 'replacement-unifi-key',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $replacement = $connection->fresh();
        $this->assertSame(IntegrationProviderConnection::STATUS_DISCONNECTED, $replacement->status);
        $this->assertFalse($replacement->requires_credential_replacement);
        $this->assertSame('disabled-old-key', Crypt::decryptString($replacement->secret_encrypted));
        $this->assertSame('replacement-unifi-key', app(IntegrationSecretMaterialService::class)->application(
            $replacement,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        $this->assertNotNull($replacement->recovery_credentials_replaced_at);
        $this->assertSame($this->admin->id, $replacement->recovery_credentials_replaced_by);
        $this->assertSame('security_review', $replacement->disabled_reason);
        $this->assertFalse(IntegrationProviderConnection::query()->forProvider('unifi')->connected()->exists());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'securityDevices.integration.unifi.recovery_credential_replaced',
            'auditable_id' => $connection->id,
        ]);

        $adapter = \Mockery::mock(ConnectionHealthCapability::class);
        $adapter->shouldReceive('testConnection')
            ->once()
            ->withArgs(fn (IntegrationProviderConnection $candidate): bool => $candidate->is($connection))
            ->andReturnTrue();
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')->once()->with('unifi', ConnectionHealthCapability::class)->andReturnTrue();
        $registry->shouldReceive('capability')->once()->with('unifi', ConnectionHealthCapability::class)->andReturn($adapter);
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/test')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(IntegrationProviderConnection::STATUS_CONNECTED, $connection->fresh()->status);
        $this->assertSame($connection->id, IntegrationProviderConnection::query()->forProvider('unifi')->connected()->value('id'));
        $this->assertSame(Integration::STATUS_ACTIVE, Integration::query()->where('provider', 'unifi')->value('status'));
    }

    public function test_provider_read_model_redacts_raw_discovery_config_errors_and_external_references(): void
    {
        $site = Site::factory()->create([]);
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'RAW-SECRET-SENTINEL',
            'secret_last4' => '0042',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_error' => 'https://private.example.test/?token=RAW-ERROR-SENTINEL',
            'config' => [
                'api_token' => 'RAW-CONFIG-SENTINEL',
                'discovered_sites' => [[
                    'external_id' => 'RAW-EXTERNAL-SITE-SENTINEL',
                    'name' => 'Head office controller',
                    'meta' => ['controller_url' => 'https://RAW-META-SENTINEL.test'],
                ]],
            ],
        ]);
        IntegrationSyncLog::create([
            'provider' => 'unifi',
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_FAILED,
            'error_message' => 'Bearer RAW-SYNC-ERROR-SENTINEL',
            'started_at' => now(),
        ]);
        Device::factory()->create([
            'provider' => 'unifi',
            'external_ref' => ['provider_entity_id' => 'RAW-DEVICE-REF-SENTINEL'],
            'meta' => ['provider_type' => 'RAW-DEVICE-META-SENTINEL'],
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/integrations/unifi');
        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $this->assertArrayHasKey('mapping_token', $props['discoveredSites'][0]);
            $this->assertArrayNotHasKey('external_id', $props['discoveredSites'][0]);
            $this->assertArrayNotHasKey('meta', $props['discoveredSites'][0]);
            $this->assertArrayNotHasKey('config', $props['providerConnection']);
            $this->assertArrayNotHasKey('error_message', $props['syncLogs'][0]);
            $this->assertArrayNotHasKey('provider_entity_id', $props['syncedDevices'][0]);
            $encoded = json_encode($props, JSON_THROW_ON_ERROR);
            foreach (['RAW-', 'private.example.test'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        });

        $token = $response->viewData('page')['props']['discoveredSites'][0]['mapping_token'];
        $this->actingAs($this->admin)->post('/security-devices/integrations/unifi/map-site', [
            'site_id' => $site->id,
            'mapping_token' => $token,
        ])->assertRedirect();

        $this->assertSame(
            'RAW-EXTERNAL-SITE-SENTINEL',
            IntegrationSiteConfig::query()->where('site_id', $site->id)->value('mapped_external_site_id'),
        );
    }

    public function test_map_site_does_not_reveal_whether_a_location_exists_outside_approved_site_access(): void
    {
        config()->set('app.debug', false);
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $siteManager = $this->managerForSite($allowedSite);
        $missingSiteId = $hiddenSite->id + 1000;
        $payload = ['mapping_token' => str_repeat('a', 64)];

        $missing = $this->actingAs($siteManager)->postJson(
            '/security-devices/integrations/unifi/map-site',
            [...$payload, 'site_id' => $missingSiteId],
        );
        $hidden = $this->actingAs($siteManager)->postJson(
            '/security-devices/integrations/unifi/map-site',
            [...$payload, 'site_id' => $hiddenSite->id],
        );

        $missing->assertNotFound();
        $hidden->assertNotFound();
        $this->assertSame($missing->getContent(), $hidden->getContent());
        $this->assertDatabaseCount('integration_site_configs', 0);
    }

    public function test_sync_devices_does_not_reveal_missing_unrelated_or_non_unifi_site_configs(): void
    {
        config()->set('app.debug', false);
        $allowedSite = Site::factory()->create([]);
        $siteManager = $this->managerForSite($allowedSite);
        $hiddenSite = Site::factory()->create([]);
        $hiddenConfig = IntegrationSiteConfig::create([
            'site_id' => $hiddenSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'unrelated-site',
            'is_active' => true,
        ]);
        $otherProviderConfig = IntegrationSiteConfig::create([
            'site_id' => $allowedSite->id,
            'provider' => 'verkada',
            'mapped_external_site_id' => 'other-provider-site',
            'is_active' => true,
        ]);
        $secondHiddenSite = Site::factory()->create([]);
        $hiddenSiteConfig = IntegrationSiteConfig::create([
            'site_id' => $secondHiddenSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'unrelated-related-site',
            'is_active' => true,
        ]);
        $missingConfigId = max($hiddenConfig->id, $otherProviderConfig->id, $hiddenSiteConfig->id) + 1000;

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldNotReceive('hasCapability');
        $registry->shouldNotReceive('capability');
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $responses = collect([$hiddenSiteConfig->id, $missingConfigId, $hiddenConfig->id, $otherProviderConfig->id])
            ->map(fn (int $siteConfigId) => $this->actingAs($siteManager)->postJson(
                '/security-devices/integrations/unifi/sync-devices',
                ['site_config_id' => $siteConfigId],
            ));

        $responses->each->assertNotFound();
        $this->assertCount(1, $responses->map->getContent()->unique());
        $this->assertDatabaseCount('integration_sync_logs', 0);
    }

    public function test_authorised_unifi_site_config_can_sync_devices(): void
    {
        $site = Site::factory()->create([]);
        $siteConfig = IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'local-site',
            'is_active' => true,
        ]);
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'test-secret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $adapter = \Mockery::mock(DeviceSyncCapability::class);
        $adapter->shouldReceive('syncDevices')
            ->once()
            ->withArgs(fn (IntegrationSiteConfig $config, IntegrationProviderConnection $connection): bool => $config->is($siteConfig) && $connection->provider === 'unifi')
            ->andReturn(new SyncResult(processed: 3, created: 1, updated: 2));
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')->once()->with('unifi', DeviceSyncCapability::class)->andReturnTrue();
        $registry->shouldReceive('capability')->once()->with('unifi', DeviceSyncCapability::class)->andReturn($adapter);
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/sync-devices', ['site_config_id' => $siteConfig->id])
            ->assertRedirect()
            ->assertSessionHas('success', 'Device sync complete. Processed 3, created 1, updated 2, errored 0.');

        $this->assertDatabaseHas('integration_sync_logs', [
            'provider' => 'unifi',
            'site_id' => $site->id,
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'items_processed' => 3,
            'items_created' => 1,
            'items_updated' => 2,
            'items_errored' => 0,
        ]);
    }

    public function test_failed_sync_is_visible_preserves_last_success_and_requires_health_recovery_before_safe_retry(): void
    {
        $site = Site::factory()->create([]);
        $siteConfig = IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'local-site',
            'is_active' => true,
        ]);
        $lastSyncedAt = now()->subDay()->startOfSecond();
        $connection = IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'test-secret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_synced_at' => $lastSyncedAt,
        ]);
        $existingDevice = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'Existing device',
        ]);

        $deviceAdapter = \Mockery::mock(DeviceSyncCapability::class);
        $deviceAdapter->shouldReceive('syncDevices')
            ->twice()
            ->andReturn(
                new SyncResult(error: 'RAW-TLS-FAILURE token=test-secret'),
                new SyncResult(processed: 1, updated: 1),
            );
        $healthAdapter = \Mockery::mock(ConnectionHealthCapability::class);
        $healthAdapter->shouldReceive('testConnection')->once()->andReturnTrue();
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->twice()
            ->with('unifi', DeviceSyncCapability::class)
            ->andReturnTrue();
        $registry->shouldReceive('capability')
            ->twice()
            ->with('unifi', DeviceSyncCapability::class)
            ->andReturn($deviceAdapter);
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('unifi', ConnectionHealthCapability::class)
            ->andReturnTrue();
        $registry->shouldReceive('capability')
            ->once()
            ->with('unifi', ConnectionHealthCapability::class)
            ->andReturn($healthAdapter);
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $failed = $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/sync-devices', ['site_config_id' => $siteConfig->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $failedConnection = $connection->fresh();
        $this->assertSame(IntegrationProviderConnection::STATUS_ERROR, $failedConnection->status);
        $this->assertSame($lastSyncedAt->toDateTimeString(), $failedConnection->last_synced_at?->toDateTimeString());
        $this->assertSame('Provider operation failed. Review the bounded diagnostic state and retry.', $failedConnection->last_error);
        $this->assertSame('Existing device', $existingDevice->fresh()->name);
        $this->assertSame(1, Device::query()->where('provider', 'unifi')->count());
        $this->assertSame(IntegrationSyncLog::STATUS_FAILED, IntegrationSyncLog::query()->sole()->status);
        $this->assertStringNotContainsString('RAW-TLS-FAILURE', json_encode([
            'session' => $failed->getSession()->all(),
            'connection' => $failedConnection->toArray(),
            'sync' => IntegrationSyncLog::query()->sole()->toArray(),
        ], JSON_THROW_ON_ERROR));

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providerConnection.status', IntegrationProviderConnection::STATUS_ERROR)
                ->where('syncLogs.0.status', IntegrationSyncLog::STATUS_FAILED)
                ->where('syncLogs.0.failure_category', 'provider_failure'));

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/sync-devices', ['site_config_id' => $siteConfig->id])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseCount('integration_sync_logs', 1);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/test')
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(IntegrationProviderConnection::STATUS_CONNECTED, $connection->fresh()->status);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/sync-devices', ['site_config_id' => $siteConfig->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $recovered = $connection->fresh();
        $this->assertSame(IntegrationProviderConnection::STATUS_CONNECTED, $recovered->status);
        $this->assertNull($recovered->last_error);
        $this->assertTrue($recovered->last_synced_at?->isAfter($lastSyncedAt));
        $this->assertDatabaseCount('integration_sync_logs', 2);
        $this->assertDatabaseHas('integration_sync_logs', [
            'provider' => 'unifi',
            'site_id' => $site->id,
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'items_processed' => 1,
            'items_updated' => 1,
        ]);
        $this->assertSame(1, Device::query()->where('provider', 'unifi')->count());
    }

    public function test_inactive_unifi_site_config_is_indistinguishable_from_missing_and_never_syncs(): void
    {
        config()->set('app.debug', false);
        $site = Site::factory()->create([]);
        $inactiveConfig = IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'inactive-site',
            'is_active' => false,
        ]);
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'test-secret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $syncCalls = 0;
        $adapter = \Mockery::mock(DeviceSyncCapability::class);
        $adapter->shouldReceive('syncDevices')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$syncCalls): SyncResult {
                $syncCalls++;
                Device::factory()->create([
                    'provider' => 'unifi',
                    'name' => 'INACTIVE-SYNC-SENTINEL',
                ]);

                return new SyncResult(processed: 1, created: 1);
            });
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')->zeroOrMoreTimes()->with('unifi', DeviceSyncCapability::class)->andReturnTrue();
        $registry->shouldReceive('capability')->zeroOrMoreTimes()->with('unifi', DeviceSyncCapability::class)->andReturn($adapter);
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $missing = $this->actingAs($this->admin)->postJson(
            '/security-devices/integrations/unifi/sync-devices',
            ['site_config_id' => $inactiveConfig->id + 1000],
        );
        $inactive = $this->actingAs($this->admin)->postJson(
            '/security-devices/integrations/unifi/sync-devices',
            ['site_config_id' => $inactiveConfig->id],
        );

        $this->assertSame([
            'statuses' => [404, 404],
            'responses_match' => true,
            'sync_calls' => 0,
            'sync_logs' => 0,
            'sentinel_devices' => 0,
        ], [
            'statuses' => [$missing->getStatusCode(), $inactive->getStatusCode()],
            'responses_match' => $missing->getContent() === $inactive->getContent(),
            'sync_calls' => $syncCalls,
            'sync_logs' => IntegrationSyncLog::query()->count(),
            'sentinel_devices' => Device::query()->where('name', 'INACTIVE-SYNC-SENTINEL')->count(),
        ]);
    }

    public function test_site_mapping_read_model_excludes_configs_outside_approved_site_access(): void
    {
        $validSite = Site::factory()->create(['name' => 'Valid Site']);
        $unrelatedSite = Site::factory()->create(['name' => 'UNRELATED-MAPPING-SENTINEL']);
        $siteManager = $this->managerForSite($validSite);
        $validConfig = IntegrationSiteConfig::create([
            'site_id' => $validSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'valid-site',
            'mapped_external_site_name' => 'Valid controller',
            'is_active' => true,
        ]);
        $unrelatedConfig = IntegrationSiteConfig::create([
            'site_id' => $unrelatedSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'unrelated-site',
            'mapped_external_site_name' => 'UNRELATED-CONTROLLER-SENTINEL',
            'is_active' => true,
        ]);

        $this->actingAs($siteManager)
            ->get('/security-devices/integrations/unifi')
            ->assertOk()
            ->assertInertia(function ($page) use ($validConfig, $unrelatedConfig): void {
                $configs = collect($page->toArray()['props']['siteConfigs']);
                $this->assertSame([$validConfig->id], $configs->pluck('id')->all());
                $this->assertNotContains($unrelatedConfig->id, $configs->pluck('id'));
                $this->assertStringNotContainsString('UNRELATED-', json_encode($configs, JSON_THROW_ON_ERROR));
            });
    }

    // ── Canonical UniFi device display ─────────────────────────────

    private function managerForSite(Site $site): User
    {
        $manager = User::factory()->create(['approved_at' => now()]);
        $manager->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
        $permissionId = Permission::query()
            ->where('key', 'securityDevices.integrations.manage')
            ->value('id');
        $this->assertNotNull($permissionId);
        $manager->permissionOverrides()->attach($permissionId, ['allowed' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $manager;
    }

    public function test_displays_unifi_devices_from_canonical_registry(): void
    {
        $device = Device::factory()->itInfrastructure()->create([
            'name' => 'UniFi AP Office',
            'provider' => 'unifi',
            'model' => 'U6-LR',
            'device_uid' => 'RAW-DEVICE-UID',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['syncedDevices'];
            $this->assertCount(1, $devices);

            $d = $devices[0];
            $this->assertEquals('UniFi AP Office', $d['name']);
            $this->assertEquals('unifi', $d['status'] !== null ? 'unifi' : 'unifi'); // provider filter works
            $this->assertEquals('U6-LR', $d['model']);
            $this->assertArrayNotHasKey('device_uid', $d);
            $this->assertArrayNotHasKey('mac_address', $d);
            $this->assertArrayHasKey('domain', $d);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertArrayHasKey('detail_url', $d);
            $this->assertStringContainsString('/security-devices/devices/', $d['detail_url']);
            $this->assertStringNotContainsString('RAW-DEVICE-UID', json_encode($d, JSON_THROW_ON_ERROR));
        });
    }

    public function test_non_unifi_devices_do_not_appear(): void
    {
        Device::factory()->create(['provider' => 'unifi', 'name' => 'UniFi Device']);
        Device::factory()->create(['provider' => 'hikvision', 'name' => 'Hikvision Camera']);
        Device::factory()->create(['provider' => 'manual', 'name' => 'Manual Device']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi');

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['syncedDevices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('UniFi Device', $devices[0]['name']);
        });
    }

    // ── Site/room context from assignments ─────────────────────────

    public function test_displays_site_context_from_assignment(): void
    {
        $site = Site::factory()->create(['name' => 'Auckland Office']);
        $device = Device::factory()->create(['provider' => 'unifi', 'name' => 'UniFi Switch']);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertEquals('Auckland Office', $d['site_name']);
        });
    }

    public function test_displays_room_context_from_assignment(): void
    {
        $site = Site::factory()->create(['name' => 'Main Office']);
        $room = SiteRoom::create([
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->create(['provider' => 'unifi', 'name' => 'UniFi AP']);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi');

        $response->assertInertia(function ($page) use ($room) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertEquals('Server Room', $d['room_name']);
            $this->assertEquals($room->id, $d['room_id']);
        });
    }

    public function test_unassigned_devices_show_unassigned(): void
    {
        Device::factory()->create(['provider' => 'unifi', 'name' => 'Floating AP']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertEquals('Unassigned', $d['site_name']);
            $this->assertNull($d['room_id']);
        });
    }

    public function test_room_assignment_updates_canonical_device_assignment(): void
    {
        $site = Site::factory()->create(['name' => 'Main Office']);
        $room = SiteRoom::create([
            'site_id' => $site->id,
            'name' => 'Comms Room',
        ]);

        $shadow = LocationHardware::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'UniFi AP',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unifi-ap-1'],
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'name' => 'UniFi AP',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'unifi-ap-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put("/security-devices/integrations/unifi/hardware/{$device->id}/room", [
                'room_id' => $room->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Device room assignment updated.');

        $active = $device->fresh()->assignments()->active()->first();

        $this->assertNotNull($active);
        $this->assertEquals('room', $active->assignable_type);
        $this->assertEquals($room->id, $active->assignable_id);

        // Phase 1 (PR P) explicitly disabled the LocationHardware shadow
        // placement sync — DeviceAssignment is the authoritative source.
        // See UnifiOperationalBridgeService::syncRoomAssignment for the
        // rationale; the shadow row is retained only for provenance.
    }

    public function test_room_assignment_does_not_reveal_missing_unrelated_or_contradictory_rooms_and_never_mutates(): void
    {
        config()->set('app.debug', false);
        $site = Site::factory()->create([]);
        $otherLocalSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $localRoom = SiteRoom::create([
            'site_id' => $site->id,
            'name' => 'Local room',
        ]);
        $wrongSiteRoom = SiteRoom::create([
            'site_id' => $otherLocalSite->id,
            'name' => 'Wrong local site room',
        ]);
        $unrelatedRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'UNRELATED-ROOM-SENTINEL',
        ]);
        $contradictoryRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'CONTRADICTORY-ROOM-SENTINEL',
        ]);
        $shadow = LocationHardware::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Protected shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'protected-device'],
        ]);
        $device = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'protected-device'],
        ]);
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $unrelatedDevice = Device::factory()->itInfrastructure()->create([
            'provider' => 'unifi',
        ]);
        $unrelatedAssignment = DeviceAssignment::create([
            'device_id' => $unrelatedDevice->id,
            'assignable_type' => 'site',
            'assignable_id' => $unrelatedSite->id,
            'assigned_at' => now(),
        ]);
        $auditCount = AuditLog::query()->count();
        $deviceUpdatedAt = $device->updated_at?->toJSON();
        $shadowUpdatedAt = $shadow->updated_at?->toJSON();

        $missingRoomId = max($localRoom->id, $wrongSiteRoom->id, $unrelatedRoom->id, $contradictoryRoom->id) + 1000;
        $responses = collect([$missingRoomId, $unrelatedRoom->id, $wrongSiteRoom->id, $contradictoryRoom->id])
            ->map(fn (int $roomId) => $this->actingAs($this->admin)->putJson(
                "/security-devices/integrations/unifi/hardware/{$device->id}/room",
                ['room_id' => $roomId],
            ));
        $unrelatedHardware = $this->actingAs($this->admin)->putJson(
            "/security-devices/integrations/unifi/hardware/{$unrelatedDevice->id}/room",
            ['room_id' => $localRoom->id],
        );

        $this->assertSame([404, 404, 404, 404], $responses->map->getStatusCode()->all());
        $this->assertCount(1, $responses->map->getContent()->unique());
        $unrelatedHardware->assertNotFound();
        $this->assertSame($deviceUpdatedAt, $device->fresh()->updated_at?->toJSON());
        $this->assertSame($shadowUpdatedAt, $shadow->fresh()->updated_at?->toJSON());
        $this->assertNull($shadow->fresh()->room_id);
        $this->assertDatabaseHas('device_assignments', [
            'id' => $assignment->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'released_at' => null,
        ]);
        $this->assertDatabaseHas('device_assignments', [
            'id' => $unrelatedAssignment->id,
            'assignable_type' => 'site',
            'assignable_id' => $unrelatedSite->id,
            'released_at' => null,
        ]);
        $this->assertSame(1, DeviceAssignment::query()->where('device_id', $device->id)->count());
        $this->assertSame(1, DeviceAssignment::query()->where('device_id', $unrelatedDevice->id)->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_room_clear_uses_canonical_active_room_site(): void
    {
        config()->set('app.debug', false);
        $localSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $unrelatedRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'Unrelated provenance room',
        ]);
        $contradictoryRoom = SiteRoom::create([
            'site_id' => $unrelatedSite->id,
            'name' => 'Contradictory provenance room',
        ]);

        $devices = collect([$unrelatedRoom, $contradictoryRoom])->map(function (SiteRoom $room, int $index) use ($localSite) {
            $shadow = LocationHardware::create([
                'site_id' => $localSite->id,
                'provider' => 'unifi',
                'category' => LocationHardware::CATEGORY_AP,
                'name' => "Local fallback shadow {$index}",
                'status' => LocationHardware::STATUS_ONLINE,
                'external_ref' => ['provider_entity_id' => "corrupt-room-device-{$index}"],
            ]);
            $device = Device::factory()->itInfrastructure()->create([
                'provider' => 'unifi',
                'legacy_location_hardware_id' => $shadow->id,
                'external_ref' => ['provider_entity_id' => "corrupt-room-device-{$index}"],
                'latitude' => '-36.84850000',
                'longitude' => '174.76330000',
                'location_description' => "Local rack {$index}",
            ]);
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_ROOM,
                'assignable_id' => $room->id,
                'assigned_at' => now(),
            ]);

            return $device;
        });

        $missing = $this->actingAs($this->admin)->putJson(
            '/security-devices/integrations/unifi/hardware/'.($devices->max('id') + 1000).'/room',
            ['room_id' => null],
        );
        $responses = $devices->map(fn (Device $device) => $this->actingAs($this->admin)->putJson(
            "/security-devices/integrations/unifi/hardware/{$device->id}/room",
            ['room_id' => null],
        ));

        $missing->assertNotFound();
        $responses->each->assertRedirect();
        foreach ($devices as $device) {
            $active = $device->fresh()->assignments()->active()->sole();
            $this->assertSame(DeviceAssignment::TARGET_SITE, $active->assignable_type);
            $this->assertSame($unrelatedSite->id, $active->assignable_id);
        }
    }

    public function test_room_clear_uses_legacy_shadow_site_as_compatibility_fallback(): void
    {
        config()->set('app.debug', false);
        $unrelatedSite = Site::factory()->create([]);
        $unrelatedShadow = LocationHardware::create([
            'site_id' => $unrelatedSite->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Unrelated provenance shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unrelated-shadow-device'],
        ]);
        $contradictoryShadow = LocationHardware::create([
            'site_id' => $unrelatedSite->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Contradictory provenance shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'contradictory-shadow-device'],
        ]);

        $devices = collect([$unrelatedShadow, $contradictoryShadow])->map(
            fn (LocationHardware $shadow) => Device::factory()->itInfrastructure()->create([
                'provider' => 'unifi',
                'legacy_location_hardware_id' => $shadow->id,
                'external_ref' => ['provider_entity_id' => $shadow->external_ref['provider_entity_id']],
                'latitude' => '-36.84850000',
                'longitude' => '174.76330000',
                'location_description' => 'Local rack',
            ])
        );

        $missing = $this->actingAs($this->admin)->putJson(
            '/security-devices/integrations/unifi/hardware/'.($devices->max('id') + 1000).'/room',
            ['room_id' => null],
        );
        $responses = $devices->map(fn (Device $device) => $this->actingAs($this->admin)->putJson(
            "/security-devices/integrations/unifi/hardware/{$device->id}/room",
            ['room_id' => null],
        ));

        $missing->assertNotFound();
        $responses->each->assertRedirect();
        foreach ($devices as $device) {
            $active = $device->fresh()->assignments()->active()->sole();
            $this->assertSame(DeviceAssignment::TARGET_SITE, $active->assignable_type);
            $this->assertSame($unrelatedSite->id, $active->assignable_id);
        }
    }

    public function test_clearing_room_restores_site_assignment(): void
    {
        $site = Site::factory()->create(['name' => 'Branch Office']);
        $room = SiteRoom::create([
            'site_id' => $site->id,
            'name' => 'Entry',
        ]);

        $shadow = LocationHardware::create([
            'site_id' => $site->id,
            'room_id' => $room->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_DOOR,
            'name' => 'UniFi Access Reader',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unifi-door-1'],
        ]);

        $device = Device::factory()->security()->create([
            'provider' => 'unifi',
            'name' => 'UniFi Access Reader',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'unifi-door-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put("/security-devices/integrations/unifi/hardware/{$device->id}/room", [
                'room_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Device room assignment updated.');

        $active = $device->fresh()->assignments()->active()->first();

        $this->assertNotNull($active);
        $this->assertEquals('site', $active->assignable_type);
        $this->assertEquals($site->id, $active->assignable_id);

        // See note above — shadow LocationHardware.room_id is intentionally
        // not cleared; the canonical DeviceAssignment carries the truth.
    }

    /**
     * @param  array<int, int>  $deviceIds
     * @return array<string, mixed>
     */
    private function captureRoomMutationState(array $deviceIds): array
    {
        return [
            'devices' => Device::query()
                ->whereIn('id', $deviceIds)
                ->orderBy('id')
                ->get()
                ->map(fn (Device $device) => $device->getAttributes())
                ->all(),
            'location_hardware' => LocationHardware::withTrashed()
                ->orderBy('id')
                ->get()
                ->map(fn (LocationHardware $hardware) => $hardware->getAttributes())
                ->all(),
            'device_assignments' => DeviceAssignment::query()
                ->whereIn('device_id', $deviceIds)
                ->orderBy('id')
                ->get()
                ->map(fn (DeviceAssignment $assignment) => $assignment->getAttributes())
                ->all(),
            'audit_logs' => AuditLog::query()
                ->orderBy('id')
                ->get()
                ->map(fn (AuditLog $audit) => $audit->getAttributes())
                ->all(),
        ];
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_canonical_fields_present(): void
    {
        Device::factory()->create([
            'provider' => 'unifi',
            'name' => 'Test AP',
            'firmware_version' => '6.5.28',
            'ip_address' => '10.42.0.99',
            'serial_number' => 'RAW-UNIFI-SERIAL',
            'mac_address' => 'DE:AD:BE:EF:00:42',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertArrayNotHasKey('device_uid', $d);
            $this->assertArrayHasKey('domain', $d);
            $this->assertArrayHasKey('category', $d);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertArrayHasKey('manufacturer', $d);
            $this->assertArrayNotHasKey('serial_number', $d);
            $this->assertArrayNotHasKey('mac_address', $d);
            $this->assertArrayNotHasKey('ip_address', $d);
            $this->assertArrayHasKey('firmware_version', $d);
            $this->assertArrayHasKey('detail_url', $d);
            $this->assertEquals('6.5.28', $d['firmware_version']);
            $encoded = json_encode($d, JSON_THROW_ON_ERROR);
            foreach (['RAW-UNIFI-SERIAL', '10.42.0.99', 'DE:AD:BE:EF:00:42'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        });
    }
}
