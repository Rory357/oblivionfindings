<?php

namespace Tests\Feature\SecurityDevices;

use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SafeOperationalData;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnifiDiscoveryFailureTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_FAILURE = 'Bearer RAW-UNIFI-DISCOVERY at https://private.example.test/?token=RAW-TOKEN';

    private User $admin;

    private Site $site;

    private IntegrationTenantSecret $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create(['tenant_id' => 1]);
        $this->secret = IntegrationTenantSecret::create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('RAW-UNIFI-API-TOKEN'),
            'status' => IntegrationTenantSecret::STATUS_CONNECTED,
            'last_synced_at' => now()->subDay(),
            'last_error' => null,
            'config' => ['discovered_sites' => [['external_id' => 'previous-site']]],
        ]);
    }

    #[DataProvider('failedDiscoveryProvider')]
    public function test_failed_discovery_is_typed_bounded_and_never_recorded_as_empty_success(
        string $scenario,
        string $expectedCategory,
    ): void {
        $this->fakeDiscovery($scenario);
        Log::spy();
        $previousSyncedAt = $this->secret->last_synced_at?->toDateTimeString();

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_error' => null,
                'last_synced_at' => $previousSyncedAt,
            ]);
            IntegrationSyncLog::query()->delete();

            $response = $this->actingAs($this->admin)
                ->from('/security-devices/integrations/unifi')
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('error');

            $secret = $this->secret->fresh();
            $log = IntegrationSyncLog::query()->sole();
            $this->assertSame(IntegrationTenantSecret::STATUS_ERROR, $secret->status);
            $this->assertSame($previousSyncedAt, $secret->last_synced_at?->toDateTimeString());
            $this->assertSame(SafeOperationalData::failureSummary(), $secret->last_error);
            $this->assertSame(IntegrationSyncLog::STATUS_FAILED, $log->status);
            $this->assertSame(SafeOperationalData::failureSummary(), $log->error_message);
            $this->assertSame('previous-site', data_get($secret->config, 'discovered_sites.0.external_id'));

            $encoded = json_encode([
                'session' => $response->getSession()->all(),
                'secret' => $secret->toArray(),
                'log' => $log->toArray(),
            ], JSON_THROW_ON_ERROR);
            foreach (['RAW-', 'private.example.test'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'UniFi discoverSites failed'
                && ($context['error_category'] ?? null) === $expectedCategory
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'RAW-'))
            ->atLeast()->once();
    }

    public static function failedDiscoveryProvider(): array
    {
        return [
            'unauthorized' => ['unauthorized', 'authentication_failure'],
            'server error' => ['server_error', 'provider_failure'],
            'connection timeout' => ['connection', 'connection_failure'],
            'malformed success body' => ['malformed', 'invalid_response'],
        ];
    }

    #[DataProvider('malformedSiteRecordProvider')]
    public function test_malformed_successful_site_records_fail_closed_without_persisting_provider_text(
        array $record,
    ): void {
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => [$record]], 200),
            'https://api.ui.com/v1/hosts' => Http::response(['data' => []], 200),
            'https://api.ui.com/v1/devices' => Http::response(['data' => []], 200),
        ]);
        Log::spy();
        $previousSyncedAt = $this->secret->last_synced_at?->toDateTimeString();

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_error' => null,
                'last_synced_at' => $previousSyncedAt,
                'config' => ['discovered_sites' => [['external_id' => 'previous-site']]],
            ]);
            IntegrationSyncLog::query()->delete();

            $response = $this->actingAs($this->admin)
                ->from('/security-devices/integrations/unifi')
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('error');

            $secret = $this->secret->fresh();
            $log = IntegrationSyncLog::query()->sole();
            $this->assertSame(IntegrationTenantSecret::STATUS_ERROR, $secret->status);
            $this->assertSame($previousSyncedAt, $secret->last_synced_at?->toDateTimeString());
            $this->assertSame(SafeOperationalData::failureSummary(), $secret->last_error);
            $this->assertSame(IntegrationSyncLog::STATUS_FAILED, $log->status);
            $this->assertSame(SafeOperationalData::failureSummary(), $log->error_message);
            $this->assertSame('previous-site', data_get($secret->config, 'discovered_sites.0.external_id'));

            $encoded = json_encode([
                'session' => $response->getSession()->all(),
                'secret' => $secret->toArray(),
                'log' => $log->toArray(),
            ], JSON_THROW_ON_ERROR);
            foreach (['RAW-', 'private.example.test'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'UniFi discoverSites failed'
                && ($context['error_category'] ?? null) === 'invalid_response'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'RAW-'))
            ->atLeast()->once();
    }

    public static function malformedSiteRecordProvider(): array
    {
        $unbounded = self::RAW_FAILURE.str_repeat('x', 300);

        return [
            'missing provider site id' => [[
                'name' => self::RAW_FAILURE,
            ]],
            'blank provider site id' => [[
                'siteId' => " \t\n",
                'name' => self::RAW_FAILURE,
            ]],
            'non-scalar provider site id' => [[
                'siteId' => ['value' => self::RAW_FAILURE],
                'name' => 'Safe name',
            ]],
            'unbounded provider site id' => [[
                'siteId' => $unbounded,
                'name' => 'Safe name',
            ]],
            'non-scalar site name' => [[
                'siteId' => 'safe-site-id',
                'name' => ['value' => self::RAW_FAILURE],
            ]],
            'unbounded site name' => [[
                'siteId' => 'safe-site-id',
                'name' => $unbounded,
            ]],
            'non-scalar projected health field' => [[
                'siteId' => 'safe-site-id',
                'name' => 'Safe name',
                'health' => ['value' => self::RAW_FAILURE],
            ]],
            'unbounded projected health field' => [[
                'siteId' => 'safe-site-id',
                'name' => 'Safe name',
                'health' => $unbounded,
            ]],
            'non-scalar projected device count' => [[
                'siteId' => 'safe-site-id',
                'name' => 'Safe name',
                'statistics' => ['counts' => ['totalDevice' => ['value' => self::RAW_FAILURE]]],
            ]],
            'unbounded projected device count' => [[
                'siteId' => 'safe-site-id',
                'name' => 'Safe name',
                'statistics' => ['counts' => ['totalDevice' => self::RAW_FAILURE.str_repeat('9', 20)]],
            ]],
        ];
    }

    #[DataProvider('failedHostDiscoveryProvider')]
    public function test_host_failure_after_site_discovery_is_typed_and_preserves_prior_count_and_sync_state(
        string $scenario,
        string $expectedCategory,
    ): void {
        $this->fakeHostDiscovery($scenario);
        Log::spy();
        $previousSyncedAt = $this->secret->last_synced_at?->toDateTimeString();
        $previousConfig = [
            'discovered_sites' => [['external_id' => 'previous-site']],
            'discovered_host_count' => 7,
            'sites_synced_at' => '2026-07-01T00:00:00+00:00',
        ];

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_error' => null,
                'last_synced_at' => $previousSyncedAt,
                'config' => $previousConfig,
            ]);
            IntegrationSyncLog::query()->delete();

            $response = $this->actingAs($this->admin)
                ->from('/security-devices/integrations/unifi')
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('error');

            $secret = $this->secret->fresh();
            $log = IntegrationSyncLog::query()->sole();
            $this->assertSame(IntegrationTenantSecret::STATUS_CONNECTED, $secret->status);
            $this->assertNull($secret->last_error);
            $this->assertSame($previousSyncedAt, $secret->last_synced_at?->toDateTimeString());
            $this->assertEquals($previousConfig, $secret->config);
            $this->assertSame(IntegrationSyncLog::STATUS_FAILED, $log->status);
            $this->assertSame(SafeOperationalData::failureSummary(), $log->error_message);

            $encoded = json_encode([
                'session' => $response->getSession()->all(),
                'secret' => $secret->toArray(),
                'log' => $log->toArray(),
            ], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('RAW-', $encoded);
            $this->assertStringNotContainsString('private.example.test', $encoded);
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'UniFi discoverHosts failed'
                && ($context['error_category'] ?? null) === $expectedCategory
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'RAW-'))
            ->atLeast()->once();
    }

    public static function failedHostDiscoveryProvider(): array
    {
        return [
            'unauthorized' => ['unauthorized', 'authentication_failure'],
            'server error' => ['server_error', 'provider_failure'],
            'connection timeout' => ['connection', 'connection_failure'],
            'malformed success body' => ['malformed', 'invalid_response'],
        ];
    }

    #[DataProvider('malformedHostRecordProvider')]
    public function test_malformed_host_records_fail_closed_and_preserve_prior_state(array $record): void
    {
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => [[
                'siteId' => 'site-1',
                'name' => 'Main Office',
            ]]], 200),
            'https://api.ui.com/v1/hosts' => Http::response(['data' => [$record]], 200),
            'https://api.ui.com/v1/devices' => Http::response(['data' => []], 200),
        ]);
        $previousConfig = [
            'discovered_sites' => [['external_id' => 'previous-site']],
            'discovered_host_count' => 3,
        ];

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_error' => null,
                'config' => $previousConfig,
            ]);
            IntegrationSyncLog::query()->delete();

            $this->actingAs($this->admin)
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertSame($previousConfig, $this->secret->fresh()->config);
            $this->assertSame(IntegrationSyncLog::STATUS_FAILED, IntegrationSyncLog::query()->sole()->status);
        }
    }

    public static function malformedHostRecordProvider(): array
    {
        $unbounded = self::RAW_FAILURE.str_repeat('x', 300);

        return [
            'missing id' => [['name' => self::RAW_FAILURE]],
            'non scalar id' => [['id' => ['raw' => self::RAW_FAILURE]]],
            'unbounded id' => [['id' => $unbounded]],
            'non scalar name' => [['id' => 'host-1', 'name' => ['raw' => self::RAW_FAILURE]]],
            'unbounded model' => [['id' => 'host-1', 'model' => $unbounded]],
            'non scalar type' => [['id' => 'host-1', 'type' => ['raw' => self::RAW_FAILURE]]],
            'non scalar controller' => [['id' => 'host-1', 'controllers' => [['name' => ['raw' => self::RAW_FAILURE]]]]],
        ];
    }

    public function test_successful_host_discovery_persists_only_a_bounded_count(): void
    {
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => [[
                'siteId' => 'site-1',
                'name' => 'Main Office',
            ]]], 200),
            'https://api.ui.com/v1/hosts' => Http::response(['data' => [[
                'id' => 'host-1',
                'name' => 'RAW-HOST-NAME',
                'model' => 'UDM-Pro',
                'controllers' => ['network'],
            ]]], 200),
            'https://api.ui.com/v1/devices' => Http::response(['data' => []], 200),
        ]);

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'config' => [
                    'discovered_sites' => [['external_id' => 'old-site']],
                    'discovered_hosts' => [['id' => 'RAW-LEGACY-HOST']],
                ],
            ]);
            IntegrationSyncLog::query()->delete();

            $response = $this->actingAs($this->admin)
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('success');

            $config = $this->secret->fresh()->config;
            $this->assertSame(1, $config['discovered_host_count'] ?? null);
            $this->assertArrayNotHasKey('discovered_hosts', $config);
            $encoded = json_encode([
                'config' => $config,
                'session' => $response->getSession()->all(),
            ], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('RAW-HOST-NAME', $encoded);
            $this->assertStringNotContainsString('RAW-LEGACY-HOST', $encoded);
        }
    }

    public function test_valid_bounded_site_record_still_advances_sync_state_through_both_entry_points(): void
    {
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => [[
                'siteId' => 'site-1',
                'name' => 'Main Office',
                'health' => 'healthy',
                'statistics' => ['counts' => ['totalDevice' => 7]],
            ]]], 200),
            'https://api.ui.com/v1/hosts' => Http::response(['data' => []], 200),
            'https://api.ui.com/v1/devices' => Http::response(['data' => []], 200),
        ]);
        $previousSyncedAt = $this->secret->last_synced_at?->toDateTimeString();

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_error' => SafeOperationalData::failureSummary(),
                'last_synced_at' => $previousSyncedAt,
                'config' => ['discovered_sites' => [['external_id' => 'previous-site']]],
            ]);
            IntegrationSyncLog::query()->delete();

            $this->actingAs($this->admin)
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('success');

            $secret = $this->secret->fresh();
            $log = IntegrationSyncLog::query()->sole();
            $this->assertSame(IntegrationTenantSecret::STATUS_CONNECTED, $secret->status);
            $this->assertNull($secret->last_error);
            $this->assertNotSame($previousSyncedAt, $secret->last_synced_at?->toDateTimeString());
            $this->assertSame(IntegrationSyncLog::STATUS_SUCCESS, $log->status);
            $this->assertEquals([
                'external_id' => 'site-1',
                'name' => 'Main Office',
                'meta' => [
                    'device_count' => 7,
                    'health_status' => 'healthy',
                    'main_device_name' => null,
                    'main_device_model' => null,
                    'main_device_role' => null,
                ],
            ], data_get($secret->config, 'discovered_sites.0'));
            $this->assertSame(0, data_get($secret->config, 'discovered_host_count'));
            $this->assertArrayNotHasKey('discovered_hosts', $secret->config);
        }
    }

    public function test_successful_empty_discovery_is_the_only_empty_result_that_advances_sync_state(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $previousSyncedAt = $this->secret->last_synced_at?->toDateTimeString();

        foreach ($this->entryPoints() as $url) {
            $this->secret->refresh()->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_error' => SafeOperationalData::failureSummary(),
                'last_synced_at' => $previousSyncedAt,
            ]);
            IntegrationSyncLog::query()->delete();

            $this->actingAs($this->admin)
                ->post($url)
                ->assertRedirect()
                ->assertSessionHas('warning');

            $secret = $this->secret->fresh();
            $log = IntegrationSyncLog::query()->sole();
            $this->assertNull($secret->last_error);
            $this->assertNotSame($previousSyncedAt, $secret->last_synced_at?->toDateTimeString());
            $this->assertSame([], data_get($secret->config, 'discovered_sites'));
            $this->assertSame(0, data_get($secret->config, 'discovered_host_count'));
            $this->assertArrayNotHasKey('discovered_hosts', $secret->config);
            $this->assertSame(IntegrationSyncLog::STATUS_PARTIAL, $log->status);
            $this->assertStringNotContainsString('RAW-', (string) $log->error_message);
        }
    }

    /** @return array<int, string> */
    private function entryPoints(): array
    {
        return [
            '/security-devices/integrations/unifi/sync-sites',
            "/sites/{$this->site->id}/integrations/unifi/sync-sites",
        ];
    }

    private function fakeDiscovery(string $scenario): void
    {
        match ($scenario) {
            'unauthorized' => Http::fake(['*' => Http::response(['message' => self::RAW_FAILURE], 401)]),
            'server_error' => Http::fake(['*' => Http::response(['message' => self::RAW_FAILURE], 500)]),
            'connection' => Http::fake(Http::failedConnection(self::RAW_FAILURE)),
            'malformed' => Http::fake(['*' => Http::response(['data' => self::RAW_FAILURE], 200)]),
        };
    }

    private function fakeHostDiscovery(string $scenario): void
    {
        $hostResponse = match ($scenario) {
            'unauthorized' => Http::response(['message' => self::RAW_FAILURE], 401),
            'server_error' => Http::response(['message' => self::RAW_FAILURE], 500),
            'connection' => Http::failedConnection(self::RAW_FAILURE),
            'malformed' => Http::response(['data' => self::RAW_FAILURE], 200),
        };

        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => [[
                'siteId' => 'site-new',
                'name' => 'New provider site',
            ]]], 200),
            'https://api.ui.com/v1/hosts' => $hostResponse,
            'https://api.ui.com/v1/devices' => Http::response(['data' => []], 200),
        ]);
    }
}
