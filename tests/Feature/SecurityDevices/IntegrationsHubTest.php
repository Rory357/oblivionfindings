<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Models\ProviderCapabilityException;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Presenters\IntegrationsWorkspacePresenter;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\IntegrationAdapterInterface;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the Security & Devices Integrations Hub landing page.
 *
 * Target: App\Domain\SecurityDevices\Http\Controllers\IntegrationsHubController
 * Route:  GET /security-devices/integrations
 */
class IntegrationsHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // support_worker lacks securityDevices.integrations.view.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/security-devices/integrations')->assertRedirect('/login');
    }

    public function test_user_without_integrations_view_is_forbidden(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/integrations')
            ->assertForbidden();
    }

    public function test_admin_sees_inertia_hub_page_with_stats_and_can_flags(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/integrations')
                ->has('providers', 3)
                ->has('stats.providers_total')
                ->missing('stats.providers_live')
                ->has('stats.providers_connected')
                ->has('stats.providers_errored')
                ->has('stats.imported_devices')
                ->has('stats.events_24h')
                ->where('stats.providers_total', 3)
                ->has('can.manage')
            );
    }

    public function test_provider_catalog_reports_typed_contract_maturity_without_static_live_labels(): void
    {
        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertInertia(function ($page) {
            $providers = collect($page->toArray()['props']['providers']);

            $slugs = $providers->pluck('slug')->all();
            $this->assertEqualsCanonicalizing(['unifi', 'queclink', 'milesight'], $slugs);

            $unifi = $providers->firstWhere('slug', 'unifi');
            $this->assertSame('/security-devices/integrations/unifi', $unifi['docs_href']);
            $this->assertSame('monitoring_topology_snapshot_collection', $unifi['runtime']['contract_state']);
            $this->assertSame('Monitoring, inventory, sync, topology, configuration and events', $unifi['runtime']['contract_label']);
            $this->assertStringContainsString('governed read-only configuration snapshots', $unifi['runtime']['contract_note']);
            $milesight = $providers->firstWhere('slug', 'milesight');
            $this->assertSame('monitoring_inventory_sync_webhook', $milesight['runtime']['contract_state']);
            $this->assertSame('Monitoring, inventory, sync and signed events', $milesight['runtime']['contract_label']);
            $this->assertSame('native_runtime_only', $providers->firstWhere('slug', 'queclink')['runtime']['contract_state']);
            $this->assertSame('Native operations only', $providers->firstWhere('slug', 'queclink')['runtime']['contract_label']);
            $this->assertSame('unavailable', $providers->firstWhere('slug', 'queclink')['connection_status']);
            $this->assertStringContainsString(
                'Direct TCP intake, canonical tracking, and governed Device Management remain available',
                $providers->firstWhere('slug', 'queclink')['runtime']['contract_note'],
            );

            foreach ($providers as $provider) {
                $this->assertArrayNotHasKey('implementation_status', $provider);
                $this->assertArrayNotHasKey('capabilities', $provider);
            }
            $this->assertArrayNotHasKey('providers_live', $page->toArray()['props']['stats']);

            // Non-configured providers should report "not_configured" by default.
            $this->assertSame('not_configured', $unifi['connection_status']);
            $this->assertFalse($unifi['connected']);
        });
    }

    public function test_health_feed_distinguishes_unsupported_never_current_stale_partial_and_failed(): void
    {
        $site = Site::factory()->create();
        IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'health-site',
            'is_active' => true,
        ]);
        $payload = fn (): array => collect(app(IntegrationsWorkspacePresenter::class)
            ->present($this->admin)['providers'])
            ->keyBy('slug')
            ->all();

        $providers = $payload();
        $this->assertSame('unsupported', $providers['queclink']['health']['state']);
        $this->assertSame('unsupported', $providers['queclink']['health']['freshness']);
        $this->assertSame('not_run', $providers['unifi']['health']['state']);
        $this->assertSame('never', $providers['unifi']['health']['freshness']);

        $cursor = ProviderCapabilityCursor::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'cursor' => 'unifi-health-v1:0',
            'last_started_at' => now()->subMinute(),
            'last_completed_at' => now()->subMinute(),
            'retry_not_before' => null,
            'exception_count' => 0,
        ]);

        $current = $payload()['unifi']['health'];
        $this->assertSame('current', $current['state']);
        $this->assertSame('current', $current['freshness']);
        $this->assertStringContainsString('zero-result', $current['summary']);
        $this->assertNotNull($current['last_collected_at']);

        $cursor->forceFill([
            'last_started_at' => now()->subHours(25),
            'last_completed_at' => now()->subHours(25),
        ])->save();
        $stale = $payload()['unifi']['health'];
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['freshness']);
        $this->assertSame('/security-devices/integrations/unifi', $stale['href']);

        $cursor->forceFill([
            'last_started_at' => now()->subMinutes(6),
            'last_completed_at' => null,
            'last_failed_at' => null,
            'last_partial_at' => null,
        ])->save();
        $this->assertSame('failed', $payload()['unifi']['health']['state']);

        $completedAt = now()->subMinute();
        $cursor->forceFill([
            'last_started_at' => $completedAt,
            'last_completed_at' => $completedAt,
            'last_partial_at' => $completedAt,
            'retry_not_before' => now()->addMinutes(5),
            'exception_count' => 1,
        ])->save();
        ProviderCapabilityException::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'code' => 'provider_rate_limited',
            'item_reference' => null,
            'occurred_at' => $completedAt,
        ]);
        $this->assertSame('partial', $payload()['unifi']['health']['state']);

        $cursor->forceFill([
            'last_partial_at' => null,
            'retry_not_before' => null,
        ])->save();
        $this->assertSame('partial', $payload()['unifi']['health']['state']);

        $cursor->forceFill([
            'last_started_at' => now(),
            'last_completed_at' => now()->subMinute(),
            'last_failed_at' => now(),
            'last_partial_at' => null,
            'retry_not_before' => null,
        ])->save();
        ProviderCapabilityException::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'code' => 'provider_collection_failed',
            'item_reference' => null,
            'occurred_at' => now(),
        ]);
        $failed = $payload()['unifi']['health'];
        $this->assertSame('failed', $failed['state']);
        $this->assertStringContainsString('failed or stopped', $failed['summary']);
    }

    public function test_health_feed_uses_the_worst_visible_mapped_site_state(): void
    {
        $currentSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        foreach ([$currentSite, $secondSite] as $site) {
            IntegrationSiteConfig::create([
                'site_id' => $site->id,
                'provider' => 'unifi',
                'mapped_external_site_id' => "health-site-{$site->id}",
                'is_active' => true,
            ]);
        }
        ProviderCapabilityCursor::query()->create([
            'site_id' => $currentSite->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'last_started_at' => now()->subMinute(),
            'last_completed_at' => now()->subMinute(),
        ]);
        $payload = fn (): array => collect(app(IntegrationsWorkspacePresenter::class)
            ->present($this->admin)['providers'])
            ->firstWhere('slug', 'unifi');

        $missing = $payload()['health'];
        $this->assertSame('not_run', $missing['state']);
        $this->assertSame('never', $missing['freshness']);

        $secondCursor = ProviderCapabilityCursor::query()->create([
            'site_id' => $secondSite->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'last_started_at' => now()->subHours(25),
            'last_completed_at' => now()->subHours(25),
        ]);
        $stale = $payload()['health'];
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['freshness']);

        $secondCursor->forceFill([
            'last_started_at' => now(),
            'last_completed_at' => now(),
        ])->save();
        $current = $payload()['health'];
        $this->assertSame('current', $current['state']);
        $this->assertSame('current', $current['freshness']);
    }

    public function test_empty_legacy_health_facade_is_not_part_of_the_adapter_contract(): void
    {
        $this->assertFalse(method_exists(IntegrationAdapterInterface::class, 'pullHealth'));
        $this->assertFalse(method_exists(UnifiAdapter::class, 'pullHealth'));
    }

    public function test_volume_aggregation_is_exact_bounded_and_has_no_per_device_queries(): void
    {
        $site = Site::factory()->create([]);
        Device::factory()->count(60)->create([
            'provider' => 'unifi',
        ]);
        Device::factory()->count(2)->create([
            'provider' => 'unifi',
            'external_ref' => ['provider_entity_id' => 'volume-duplicate'],
        ]);
        foreach (range(1, 120) as $number) {
            IntegrationSyncLog::create([
                'provider' => 'unifi',
                'site_id' => $site->id,
                'action' => 'sync_devices',
                'status' => $number === 120
                    ? IntegrationSyncLog::STATUS_FAILED
                    : IntegrationSyncLog::STATUS_SUCCESS,
                'items_processed' => $number,
                'items_errored' => $number === 120 ? 2 : 0,
                'started_at' => now()->subMinutes(120 - $number),
                'completed_at' => now()->subMinutes(120 - $number),
            ]);
        }

        $selectQueries = 0;
        $syncQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$selectQueries, &$syncQueries): void {
            if (! str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                return;
            }
            $selectQueries++;
            if (str_contains(strtolower($query->sql), 'integration_sync_logs')) {
                $syncQueries[] = strtolower($query->sql);
            }
        });

        $payload = app(IntegrationsWorkspacePresenter::class)->present($this->admin);
        $unifi = collect($payload['providers'])->firstWhere('slug', 'unifi');

        $this->assertSame(62, $unifi['reconciliation']['imported_devices']);
        $this->assertSame(62, $unifi['reconciliation']['unassigned_devices']);
        $this->assertSame(1, $unifi['reconciliation']['duplicate_candidates']);
        $this->assertSame(IntegrationSyncLog::STATUS_FAILED, $unifi['sync']['status']);
        $this->assertSame(120, $unifi['sync']['items_processed']);
        $this->assertSame(2, $unifi['sync']['items_errored']);
        $this->assertLessThanOrEqual(45, $selectQueries, 'Integrations aggregation performs volume-dependent queries.');
        $this->assertNotEmpty($syncQueries);
        $this->assertFalse(
            collect($syncQueries)->contains(fn (string $sql): bool => str_contains($sql, 'select *')),
            'Integrations aggregation loads unbounded sync-log model history.',
        );
    }

    public function test_stats_ignore_unverified_legacy_cloud_state_while_retaining_a_removal_exception(): void
    {
        // Seed one connected + one errored secret for the application.
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'dummy',
            'secret_last4' => '1234',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        IntegrationProviderConnection::create([
            'provider' => 'queclink',
            'secret_encrypted' => 'dummy',
            'secret_last4' => '5678',
            'status' => IntegrationProviderConnection::STATUS_ERROR,
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.providers_connected', 1)
            ->where('stats.providers_errored', 0)
            ->where('providers.1.slug', 'queclink')
            ->where('providers.1.connection_status', 'unavailable')
            ->where('providers.1.connected', false)
            ->where('providers.1.exceptions.0.type', 'legacy_cloud_credential')
        );
    }

    public function test_provider_state_is_application_wide_and_admin_rollups_cover_all_sites(): void
    {

        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'provider-secret',
            'secret_last4' => '0042',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        IntegrationProviderConnection::create([
            'provider' => 'milesight',
            'secret_encrypted' => 'second-provider-secret',
            'secret_last4' => '0077',
            'status' => IntegrationProviderConnection::STATUS_ERROR,
        ]);

        $firstSiteDevice = Device::factory()->create(['provider' => 'unifi']);
        $secondSiteDevice = Device::factory()->create(['provider' => 'milesight']);

        foreach ([['device' => $firstSiteDevice, 'source' => 'unifi'], ['device' => $secondSiteDevice, 'source' => 'milesight']] as $event) {
            DeviceEvent::create([
                'device_id' => $event['device']->id,
                'event_type' => 'heartbeat',
                'severity' => 'info',
                'source' => $event['source'],
                'occurred_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $providers = collect($props['providers']);

            $this->assertSame(1, $props['stats']['providers_connected']);
            $this->assertSame(1, $props['stats']['providers_errored']);
            $this->assertSame(2, $props['stats']['imported_devices']);
            $this->assertSame(2, $props['stats']['events_24h']);
            $this->assertSame('connected', $providers->firstWhere('slug', 'unifi')['connection_status']);
            $this->assertSame('error', $providers->firstWhere('slug', 'milesight')['connection_status']);
        });
    }

    public function test_provider_health_reconciles_visible_mappings_sync_and_import_exceptions_without_raw_values(): void
    {

        $site = Site::factory()->create(['name' => 'Harbour House']);
        $secondSite = Site::factory()->create(['name' => 'Southern House']);

        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'RAW-PROVIDER-SECRET',
            'secret_last4' => '0042',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_tested_at' => now()->subDay(),
            'last_synced_at' => now()->subHours(30),
            'rotated_at' => now()->subDays(120),
            'last_error' => 'https://user:password@example.test/controller?token=RAW-TOKEN',
            'config' => ['api_token' => 'RAW-CONFIG-SECRET'],
        ]);
        IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_DISCONNECTED,
            'mapped_external_site_id' => null,
            'mapped_external_site_name' => null,
            'overrides' => ['token' => 'RAW-OVERRIDE'],
            'is_active' => false,
        ]);
        IntegrationSiteConfig::create([
            'site_id' => $secondSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'southern-controller',
            'is_active' => true,
        ]);
        IntegrationSyncLog::create([
            'provider' => 'unifi',
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_FAILED,
            'items_processed' => 5,
            'items_errored' => 2,
            'error_message' => 'Authorization Bearer RAW-TOKEN failed at https://private.example.test/site/42',
            'started_at' => now()->subHours(30),
            'completed_at' => now()->subHours(30),
        ]);

        $first = Device::factory()->create([
            'provider' => 'unifi',
            'external_ref' => ['provider_entity_id' => 'duplicate-id'],
            'config' => ['credential' => 'RAW-DEVICE-CONFIG'],
            'meta' => ['payload' => 'RAW-DEVICE-META'],
        ]);
        Device::factory()->create([
            'provider' => 'unifi',
            'external_ref' => ['provider_entity_id' => 'duplicate-id'],
        ]);
        DeviceAssignment::create([
            'device_id' => $first->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $unifi = collect($props['providers'])->firstWhere('slug', 'unifi');
            $types = collect($unifi['exceptions'])->pluck('type');

            $this->assertSame(2, $unifi['site_mapping']['total']);
            $this->assertSame(1, $unifi['site_mapping']['mapped']);
            $this->assertSame(1, $unifi['site_mapping']['unmapped']);
            $this->assertSame('stale', $unifi['sync']['freshness']);
            $this->assertSame('failed', $unifi['sync']['status']);
            $this->assertSame(2, $unifi['reconciliation']['imported_devices']);
            $this->assertSame(1, $unifi['reconciliation']['unassigned_devices']);
            $this->assertSame(1, $unifi['reconciliation']['duplicate_candidates']);
            $this->assertSame(['duplicate_candidate', 'health_collection_not_run', 'integration_error', 'stale_sync', 'unassigned_import', 'unmapped_site'], $types->sort()->values()->all());
            $links = collect($unifi['exceptions'])->keyBy('type');
            $this->assertSame('/security-devices/devices?view=unassigned', $links['unassigned_import']['href']);
            $this->assertSame('/security-devices/devices', $links['duplicate_candidate']['href']);
            $this->assertSame(array_sum(collect($props['providers'])->pluck('exception_count')->all()), $props['stats']['exceptions']);
            $this->assertSame('0042', $unifi['credential']['reference']);
            $this->assertSame('rotation_due', $unifi['credential']['rotation_state']);
            $this->assertStringNotContainsString('RAW-', json_encode($props, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('private.example.test', json_encode($props, JSON_THROW_ON_ERROR));
        });
    }

    public function test_view_only_user_gets_scoped_health_but_no_credentials_or_dead_manage_links(): void
    {

        $manage = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
        $this->admin->permissionOverrides()->attach($manage->id, ['allowed' => false]);

        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'RAW-SECRET',
            'secret_last4' => '1234',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.manage', false)
                ->where('providers.0.docs_href', null)
                ->where('providers.0.health.href', null)
                ->missing('providers.0.credential')
            );

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/unifi')
            ->assertForbidden();
    }

    public function test_provider_route_names_remain_registered(): void
    {
        $this->assertSame('/security-devices/integrations/unifi', route('security-devices.integrations.unifi', absolute: false));
        $this->assertSame('/security-devices/integrations/milesight', route('security-devices.integrations.milesight', absolute: false));
        $this->assertSame('/security-devices/integrations/queclink', route('security-devices.integrations.queclink', absolute: false));
    }

    public function test_missing_credentials_are_actionable_and_native_device_monitoring_support_is_reported(): void
    {

        Device::factory()->create([
            'provider' => 'milesight',
            'meta' => ['capabilities' => ['monitoring' => false]],
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $provider = collect($page->toArray()['props']['providers'])->firstWhere('slug', 'milesight');
                $exceptions = collect($provider['exceptions'])->keyBy('type');
                $this->assertSame('Add credentials', $exceptions['missing_credentials']['action']);
                $this->assertSame('/security-devices/integrations/milesight', $exceptions['missing_credentials']['href']);
                $this->assertFalse($exceptions->has('unsupported_check'));
                $this->assertSame(0, $provider['reconciliation']['unsupported_checks']);
                $this->assertSame('supported', $provider['monitoring_support']['state']);
                $this->assertSame('provider', $provider['monitoring_support']['scope']);
                $this->assertSame(
                    [
                        'connection_health',
                        'inventory_discovery',
                        'device_sync',
                        'observation_collection',
                        'webhook_verification',
                    ],
                    collect($provider['runtime']['capabilities'])->all(),
                );
            });
    }

    public function test_site_restricted_viewer_counts_only_accessible_mappings_and_devices(): void
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        foreach ([$allowedSite, $hiddenSite] as $site) {
            IntegrationSiteConfig::create([
                'site_id' => $site->id,
                'provider' => 'unifi',
                'mapped_external_site_id' => "site-{$site->id}",
                'is_active' => true,
            ]);
            $device = Device::factory()->create(['provider' => 'unifi']);
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }
        ProviderCapabilityCursor::query()->create([
            'site_id' => $allowedSite->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'last_started_at' => now(),
            'last_completed_at' => now(),
        ]);
        ProviderCapabilityCursor::query()->create([
            'site_id' => $hiddenSite->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'last_started_at' => now(),
            'last_failed_at' => now(),
        ]);
        ProviderCapabilityException::query()->create([
            'site_id' => $hiddenSite->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'code' => 'provider_collection_failed',
            'item_reference' => null,
            'occurred_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get('/security-devices/integrations')
            ->assertOk()
            ->assertInertia(function ($page) use ($allowedSite): void {
                $unifi = collect($page->toArray()['props']['providers'])->firstWhere('slug', 'unifi');
                $this->assertSame(1, $unifi['site_mapping']['total']);
                $this->assertSame($allowedSite->id, $unifi['site_mapping']['sites'][0]['id']);
                $this->assertSame(1, $unifi['reconciliation']['imported_devices']);
                $this->assertSame('current', $unifi['health']['state']);
            });
    }

    public function test_site_restricted_reconciliation_uses_worst_latest_site_state_and_excludes_global_or_hidden_counts(): void
    {
        $allowed = Site::factory()->create([]);
        $secondAllowed = Site::factory()->create([]);
        $hidden = Site::factory()->create([]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowed->id,
            'secondary_site_ids' => [$secondAllowed->id],
        ]);

        foreach ([
            [null, IntegrationSyncLog::STATUS_FAILED, 91, now()],
            [$hidden->id, IntegrationSyncLog::STATUS_FAILED, 82, now()],
            [$allowed->id, IntegrationSyncLog::STATUS_FAILED, 3, now()->subMinute()],
            [$secondAllowed->id, IntegrationSyncLog::STATUS_SUCCESS, 0, now()],
        ] as [$siteId, $status, $errors, $at]) {
            IntegrationSyncLog::create([
                'provider' => 'unifi', 'site_id' => $siteId,
                'action' => 'sync_devices', 'status' => $status,
                'items_processed' => 100, 'items_errored' => $errors,
                'started_at' => $at, 'completed_at' => $at,
            ]);
        }

        $this->actingAs($viewer)->get('/security-devices/integrations')->assertOk()->assertInertia(function ($page): void {
            $unifi = collect($page->toArray()['props']['providers'])->firstWhere('slug', 'unifi');
            $this->assertSame('failed', $unifi['sync']['status']);
            $this->assertSame(3, $unifi['sync']['items_errored']);
            $this->assertSame(200, $unifi['sync']['items_processed']);
        });
    }

    public function test_any_accessible_stale_site_makes_reconciled_freshness_stale(): void
    {
        $current = Site::factory()->create([]);
        $stale = Site::factory()->create([]);
        $hidden = Site::factory()->create([]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $current->id, 'secondary_site_ids' => [$stale->id],
        ]);
        foreach ([
            [$current->id, now()],
            [$stale->id, now()->subHours(30)],
            [$hidden->id, now()->subHours(40)],
            [null, now()->subHours(50)],
        ] as [$siteId, $at]) {
            IntegrationSyncLog::create([
                'provider' => 'unifi', 'site_id' => $siteId,
                'action' => 'sync_devices', 'status' => IntegrationSyncLog::STATUS_SUCCESS,
                'items_processed' => 1, 'items_errored' => 0,
                'started_at' => $at, 'completed_at' => $at,
            ]);
        }

        $this->actingAs($viewer)->get('/security-devices/integrations')->assertOk()->assertInertia(function ($page): void {
            $provider = collect($page->toArray()['props']['providers'])->firstWhere('slug', 'unifi');
            $this->assertSame('stale', $provider['sync']['freshness']);
            $this->assertSame(1, $provider['sync']['stale_site_count']);
            $this->assertSame(2, $provider['sync']['affected_site_count']);
            $staleException = collect($provider['exceptions'])->firstWhere('type', 'stale_sync');
            $this->assertSame(1, $staleException['count']);
        });
    }

    public function test_enabled_site_credential_satisfies_configuration_and_reports_sanitized_site_state(): void
    {
        $site = Site::factory()->create([]);
        IntegrationSiteConfig::create([
            'site_id' => $site->id, 'provider' => 'milesight',
            'mapped_external_site_id' => 'RAW-MAPPING', 'is_active' => true,
        ]);
        IntegrationSiteSecret::create([
            'site_id' => $site->id, 'provider' => 'milesight',
            'capability' => 'gateway', 'base_url' => 'https://RAW-SITE-HOST.test',
            'secret_encrypted' => 'RAW-SITE-SECRET', 'is_enabled' => true,
            'last_error' => 'Bearer RAW-ENABLED-SITE-ERROR', 'last_tested_at' => now()->subHour(),
        ]);
        IntegrationSiteSecret::create([
            'site_id' => $site->id, 'provider' => 'milesight',
            'capability' => 'sensor_api', 'base_url' => 'https://RAW-DISABLED-HOST.test',
            'secret_encrypted' => 'RAW-DISABLED-SECRET', 'is_enabled' => false,
            'last_error' => 'Bearer RAW-SITE-ERROR',
        ]);

        $this->actingAs($this->admin)->get('/security-devices/integrations')->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $provider = collect($props['providers'])->firstWhere('slug', 'milesight');
            $this->assertFalse(collect($provider['exceptions'])->contains('type', 'missing_credentials'));
            $this->assertSame(1, $provider['credential']['site_credentials']['enabled']);
            $this->assertSame(2, $provider['credential']['site_credentials']['needs_attention']);
            $this->assertTrue(collect($provider['exceptions'])->contains('type', 'site_credential_error'));
            $this->assertSame('/security-devices/integrations/milesight', collect($provider['exceptions'])->firstWhere('type', 'site_credential_error')['href']);
            $this->assertSame('error', $provider['connection_status']);
            $this->assertFalse($provider['connected']);
            $this->assertSame(1, $props['stats']['providers_errored']);
            $this->assertSame('unknown', $provider['credential']['rotation_state']);
            $this->assertSame('site_credentials_configured', $provider['credential']['display_state']);
            $this->assertStringNotContainsString('RAW-', json_encode($provider, JSON_THROW_ON_ERROR));
        });

        $this->actingAs($this->admin)->get('/security-devices/integrations/milesight')->assertOk()->assertInertia(function ($page) use ($site): void {
            $rows = $page->toArray()['props']['siteCredentials'];
            $this->assertCount(2, $rows);
            $gateway = collect($rows)->firstWhere('capability', 'gateway');
            $this->assertSame($site->id, $gateway['site_id']);
            $this->assertSame($site->name, $gateway['site_name']);
            $this->assertTrue($gateway['enabled']);
            $this->assertSame('provider_failure', $gateway['failure_category']);
            $this->assertNotNull($gateway['last_tested_at']);
            $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
            foreach (['RAW-', 'base_url', 'secret_encrypted', 'last_error'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        });
    }

    public function test_site_credential_health_uses_test_evidence_and_keeps_disabled_credentials_neutral(): void
    {
        $site = Site::factory()->create([]);
        foreach ([
            ['provider' => 'milesight', 'capability' => 'untested', 'is_enabled' => true, 'last_tested_at' => null, 'last_error' => null],
            ['provider' => 'queclink', 'capability' => 'tested', 'is_enabled' => true, 'last_tested_at' => now(), 'last_error' => null],
            ['provider' => 'queclink', 'capability' => 'disabled', 'is_enabled' => false, 'last_tested_at' => null, 'last_error' => null],
            ['provider' => 'unifi', 'capability' => 'failed', 'is_enabled' => true, 'last_tested_at' => now(), 'last_error' => 'RAW-MIXED-FAILURE'],
        ] as $credential) {
            IntegrationSiteSecret::create(array_merge([
                'site_id' => $site->id,
                'base_url' => 'https://RAW-MIXED-HOST.test',
                'secret_encrypted' => 'RAW-MIXED-SECRET',
            ], $credential));
        }

        $this->actingAs($this->admin)->get('/security-devices/integrations')->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $providers = collect($props['providers'])->keyBy('slug');
            $milesight = $providers['milesight'];
            $queclink = $providers['queclink'];
            $unifi = $providers['unifi'];

            $this->assertSame('untested', $milesight['connection_status']);
            $this->assertFalse($milesight['connected']);
            $this->assertSame(1, $milesight['credential']['site_credentials']['total']);
            $this->assertSame(1, $milesight['credential']['site_credentials']['enabled']);
            $this->assertSame(1, $milesight['credential']['site_credentials']['needs_attention']);
            $this->assertFalse(collect($milesight['exceptions'])->contains('type', 'site_credential_error'));
            $this->assertSame(1, collect($milesight['exceptions'])->firstWhere('type', 'site_credential_untested')['count']);
            $this->assertSame('unavailable', $queclink['connection_status']);
            $this->assertFalse($queclink['connected']);
            $this->assertSame(2, $queclink['credential']['site_credentials']['total']);
            $this->assertSame(1, $queclink['credential']['site_credentials']['enabled']);
            $this->assertSame(0, $queclink['credential']['site_credentials']['needs_attention']);
            $this->assertFalse(collect($queclink['exceptions'])->contains('type', 'site_credential_error'));
            $this->assertSame('error', $unifi['connection_status']);
            $this->assertFalse($unifi['connected']);
            $this->assertSame(0, $props['stats']['providers_connected']);
            $this->assertSame(1, $props['stats']['providers_errored']);
        });

        $this->actingAs($this->admin)->get('/security-devices/integrations/milesight')->assertOk()->assertInertia(function ($page): void {
            $rows = collect($page->toArray()['props']['siteCredentials'])->keyBy('capability');
            $this->assertSame('untested', $rows['untested']['state']);
            $this->assertNull($rows['untested']['failure_category']);
        });

        $this->actingAs($this->admin)->get('/security-devices/integrations/queclink')->assertOk()->assertInertia(function ($page): void {
            $rows = collect($page->toArray()['props']['siteCredentials'])->keyBy('capability');
            $this->assertSame('connected', $rows['tested']['state']);
            $this->assertNull($rows['tested']['failure_category']);
            $this->assertSame('disabled', $rows['disabled']['state']);
            $this->assertNull($rows['disabled']['failure_category']);
        });

        $this->actingAs($this->admin)->get('/security-devices/integrations/unifi')->assertOk()->assertInertia(function ($page): void {
            $rows = collect($page->toArray()['props']['siteCredentials'])->keyBy('capability');
            $this->assertSame('error', $rows['failed']['state']);
            $this->assertSame('provider_failure', $rows['failed']['failure_category']);
        });
    }

    public function test_view_only_exception_links_are_null_when_destination_permission_is_missing(): void
    {

        foreach (['securityDevices.integrations.manage', 'securityDevices.devices.view'] as $permissionKey) {
            $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
            $this->admin->permissionOverrides()->attach($permission->id, ['allowed' => false]);
        }
        $device = Device::factory()->create(['provider' => 'unifi']);
        Device::factory()->create([
            'provider' => 'unifi',
            'external_ref' => ['provider_entity_id' => 'duplicate'],
        ]);
        $device->update(['external_ref' => ['provider_entity_id' => 'duplicate']]);

        $this->actingAs($this->admin)->get('/security-devices/integrations')->assertOk()->assertInertia(function ($page): void {
            $provider = collect($page->toArray()['props']['providers'])->firstWhere('slug', 'unifi');
            $exceptions = collect($provider['exceptions'])->keyBy('type');
            $this->assertNull($exceptions['unassigned_import']['href']);
            $this->assertNull($exceptions['duplicate_candidate']['href']);
        });
    }

    public function test_all_provider_drill_down_get_routes_remain_usable_for_manager(): void
    {
        foreach (['unifi', 'milesight', 'queclink'] as $provider) {
            $response = $this->actingAs($this->admin)->get("/security-devices/integrations/{$provider}");
            $response->assertOk();
            $encoded = $response->getContent();
            $this->assertStringNotContainsString('secret_encrypted', $encoded);
        }
    }

    public function test_non_unifi_provider_read_models_expose_only_bounded_connection_and_sync_state(): void
    {
        foreach (['milesight'] as $provider) {
            IntegrationProviderConnection::create([
                'provider' => $provider,
                'secret_encrypted' => 'RAW-'.$provider.'-SECRET', 'secret_last4' => '0042',
                'status' => IntegrationProviderConnection::STATUS_ERROR,
                'last_error' => 'https://RAW-'.$provider.'-ERROR.test/?token=secret',
                'config' => ['base_url' => 'https://RAW-'.$provider.'-HOST.test', 'token' => 'RAW-CONFIG'],
            ]);
            IntegrationSyncLog::create([
                'provider' => $provider, 'action' => 'sync_devices',
                'status' => IntegrationSyncLog::STATUS_FAILED,
                'error_message' => 'Bearer RAW-'.$provider.'-SYNC', 'started_at' => now(),
            ]);

            $response = $this->actingAs($this->admin)->get("/security-devices/integrations/{$provider}");
            $response->assertOk()->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $this->assertTrue($props['providerConnection']['endpoint_configured']);
                $this->assertSame('provider_failure', $props['syncLogs'][0]['failure_category']);
                $encoded = json_encode($props, JSON_THROW_ON_ERROR);
                foreach (['RAW-', 'base_url', 'last_error', 'error_message'] as $sentinel) {
                    $this->assertStringNotContainsString($sentinel, $encoded);
                }
            });
        }
    }
}
