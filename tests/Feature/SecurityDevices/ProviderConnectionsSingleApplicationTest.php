<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Permission;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ProviderConnectionsSingleApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_provider_connection_is_application_wide_and_uses_the_new_read_model_contract(): void
    {
        $manager = $this->manager();
        IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('RAW-PROVIDER-SECRET'),
            'secret_last4' => '0042',
            'status' => 'connected',
            'config' => [
                'base_url' => 'https://private-provider.example.test',
                'api_token' => 'RAW-PROVIDER-CONFIG',
            ],
            'last_error' => 'Bearer RAW-PROVIDER-ERROR',
        ]);

        $response = $this->actingAs($manager)->get('/security-devices/integrations/milesight');

        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            $this->assertArrayHasKey('providerConnection', $props);
            $this->assertSame('connected', $props['providerConnection']['status']);
            $this->assertSame('0042', $props['providerConnection']['secret_last4']);
            $this->assertTrue($props['providerConnection']['endpoint_configured']);

            $encoded = json_encode($props, JSON_THROW_ON_ERROR);
            foreach (['RAW-PROVIDER-', 'private-provider.example.test'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        });
    }

    public function test_provider_identity_is_globally_unique(): void
    {
        IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('first-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
        ]);

        $this->expectException(QueryException::class);

        IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('second-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_queclink_cloud_contract_is_unavailable_and_legacy_credentials_can_only_be_removed(): void
    {
        $manager = $this->manager();
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'queclink',
            'secret_encrypted' => Crypt::encryptString('RAW-LEGACY-QUECLINK-KEY'),
            'secret_last4' => '0042',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_tested_at' => now(),
            'config' => ['base_url' => 'https://RAW-QUECLINK-CLOUD.test'],
        ]);

        $this->actingAs($manager)
            ->get('/security-devices/integrations/queclink')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $this->assertSame('unavailable', $props['cloudIntegration']['status']);
                $this->assertTrue($props['cloudIntegration']['legacy_credential_stored']);
                $this->assertSame('0042', $props['cloudIntegration']['legacy_credential_last4']);
                $this->assertArrayNotHasKey('providerConnection', $props);
                $this->assertStringNotContainsString('RAW-', json_encode($props, JSON_THROW_ON_ERROR));
            });

        $this->actingAs($manager)
            ->post('/security-devices/integrations/queclink/key', [
                'api_key' => 'replacement-key',
                'base_url' => 'https://replacement.example.test',
            ])
            ->assertStatus(405);

        foreach (['test', 'rotate'] as $action) {
            $this->actingAs($manager)
                ->post("/security-devices/integrations/queclink/{$action}", [
                    'api_key' => 'replacement-key',
                    'base_url' => 'https://replacement.example.test',
                ])
                ->assertNotFound();
        }

        $this->assertSame($connection->id, IntegrationProviderConnection::query()->where('provider', 'queclink')->sole()->id);

        $this->actingAs($manager)
            ->delete('/security-devices/integrations/queclink/key')
            ->assertRedirect();
        $this->assertDatabaseMissing($connection->getTable(), ['provider' => 'queclink']);
    }

    public function test_saving_milesight_oauth_credentials_updates_the_single_provider_connection(): void
    {
        $manager = $this->manager();
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('old-secret'),
            'secret_last4' => '0000',
            'status' => 'connected',
        ]);

        $this->actingAs($manager)->post('/security-devices/integrations/milesight/key', [
            'client_id' => 'client-123',
            'client_secret' => 'replacement-secret-9876',
            'base_url' => 'https://milesight.example.test',
        ])->assertRedirect();

        $saved = IntegrationProviderConnection::query()->where('provider', 'milesight')->sole();
        $this->assertSame($connection->id, $saved->id);
        $this->assertSame('9876', $saved->secret_last4);
        $this->assertSame('replacement-secret-9876', Crypt::decryptString($saved->secret_encrypted));
        $this->assertSame('client-123', $saved->config['client_id']);
        $this->assertSame('https://milesight.example.test', $saved->config['base_url']);
        $this->assertSame(IntegrationProviderConnection::STATUS_DISCONNECTED, $saved->status);
        $this->assertSame(1, Integration::query()->where('provider', 'milesight')->count());
    }

    public function test_milesight_webhook_secret_is_encrypted_configurable_and_never_projected(): void
    {
        $manager = $this->manager();
        IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('oauth-client-secret'),
            'secret_last4' => 'cret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => ['client_id' => 'client-123'],
        ]);
        $webhookSecret = 'RAW-MSC-WEBHOOK-SECRET-9876';

        $this->actingAs($manager)
            ->post('/security-devices/integrations/milesight/webhook', [
                'webhook_secret' => 'RAW-SHORT',
            ])
            ->assertSessionHasErrors('webhook_secret');
        $this->assertStringNotContainsString('RAW-SHORT', json_encode(session()->all(), JSON_THROW_ON_ERROR));

        $this->actingAs($manager)
            ->post('/security-devices/integrations/milesight/webhook', [
                'webhook_secret' => $webhookSecret,
            ])
            ->assertRedirect();

        $connection = IntegrationProviderConnection::query()->where('provider', 'milesight')->sole();
        $this->assertSame($webhookSecret, Crypt::decryptString($connection->config['webhook_secret_encrypted']));
        $this->assertSame('9876', $connection->config['webhook_secret_last4']);
        $this->assertNotNull($connection->config['webhook_configured_at']);
        $encryptedWebhookSecret = $connection->config['webhook_secret_encrypted'];

        $this->actingAs($manager)
            ->get('/security-devices/integrations/milesight')
            ->assertOk()
            ->assertInertia(function ($page) use ($encryptedWebhookSecret, $webhookSecret): void {
                $props = $page->toArray()['props'];
                $this->assertTrue($props['providerConnection']['webhook_configured']);
                $this->assertSame('9876', $props['providerConnection']['webhook_secret_last4']);
                $this->assertStringEndsWith('/webhooks/milesight', $props['providerConnection']['webhook_url']);
                $this->assertNull($props['providerConnection']['last_webhook_received_at']);
                $this->assertStringNotContainsString($webhookSecret, json_encode($props, JSON_THROW_ON_ERROR));
                $this->assertStringNotContainsString($encryptedWebhookSecret, json_encode($props, JSON_THROW_ON_ERROR));
            });

        $this->actingAs($manager)
            ->delete('/security-devices/integrations/milesight/webhook')
            ->assertRedirect();
        $config = IntegrationProviderConnection::query()->where('provider', 'milesight')->sole()->config;
        $this->assertArrayNotHasKey('webhook_secret_encrypted', $config);
        $this->assertArrayNotHasKey('webhook_secret_last4', $config);
        $this->assertArrayNotHasKey('webhook_configured_at', $config);
    }

    public function test_site_credentials_follow_canonical_site_access(): void
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $manager = $this->manager($allowedSite);

        IntegrationSiteSecret::query()->create([
            'site_id' => $allowedSite->id,
            'provider' => 'milesight',
            'capability' => 'network',
            'secret_encrypted' => Crypt::encryptString('allowed-secret'),
            'is_enabled' => true,
            'last_tested_at' => now(),
        ]);
        IntegrationSiteSecret::query()->create([
            'site_id' => $hiddenSite->id,
            'provider' => 'milesight',
            'capability' => 'network',
            'secret_encrypted' => Crypt::encryptString('HIDDEN-SITE-SECRET'),
            'is_enabled' => true,
            'last_tested_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get('/security-devices/integrations/milesight')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('siteCredentials', 1)
                ->where('siteCredentials.0.site_id', $allowedSite->id)
                ->where('siteCredentials.0.site_name', 'Allowed Site'));
    }

    public function test_milesight_application_mapping_uses_opaque_discovery_token_and_canonical_site_access(): void
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $secondAllowedSite = Site::factory()->create(['name' => 'Second Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $manager = $this->manager($allowedSite);
        HrEmployeeProfile::query()
            ->where('user_id', $manager->id)
            ->update(['secondary_site_ids' => [$secondAllowedSite->id]]);
        IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('client-secret'),
            'secret_last4' => 'cret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => [
                'client_id' => 'client-123',
                'discovered_applications' => [[
                    'external_id' => 'RAW-APPLICATION-ID',
                    'name' => 'Care sensors',
                    'meta' => ['device_count' => 4],
                ]],
            ],
        ]);

        $response = $this->actingAs($manager)->get('/security-devices/integrations/milesight');
        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $mappingToken = $props['discoveredApplications'][0]['mapping_token'];

        $this->assertSame('Care sensors', $props['discoveredApplications'][0]['name']);
        $this->assertSame(4, $props['discoveredApplications'][0]['device_count']);
        $this->assertStringNotContainsString('RAW-APPLICATION-ID', json_encode($props, JSON_THROW_ON_ERROR));

        $this->actingAs($manager)->post('/security-devices/integrations/milesight/applications/map', [
            'site_id' => $hiddenSite->id,
            'mapping_token' => $mappingToken,
        ])->assertNotFound();
        $this->assertSame(0, IntegrationSiteConfig::query()->count());

        $this->actingAs($manager)->post('/security-devices/integrations/milesight/applications/map', [
            'site_id' => $allowedSite->id,
            'mapping_token' => $mappingToken,
        ])->assertRedirect();

        $mapping = IntegrationSiteConfig::query()->sole();
        $this->assertSame($allowedSite->id, $mapping->site_id);
        $this->assertSame('milesight', $mapping->provider);
        $this->assertSame('RAW-APPLICATION-ID', $mapping->mapped_external_site_id);
        $this->assertSame('Care sensors', $mapping->mapped_external_site_name);

        $this->actingAs($manager)->post('/security-devices/integrations/milesight/applications/map', [
            'site_id' => $secondAllowedSite->id,
            'mapping_token' => $mappingToken,
        ])->assertUnprocessable();
        $this->assertSame(1, IntegrationSiteConfig::query()->count());
    }

    public function test_provider_sync_history_only_includes_application_and_accessible_site_runs(): void
    {
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $manager = $this->manager($allowedSite);

        foreach (['milesight'] as $provider) {
            $applicationLog = IntegrationSyncLog::query()->create([
                'provider' => $provider,
                'site_id' => null,
                'action' => 'discover_sites',
                'status' => IntegrationSyncLog::STATUS_SUCCESS,
                'started_at' => now()->subMinutes(3),
            ]);
            $allowedLog = IntegrationSyncLog::query()->create([
                'provider' => $provider,
                'site_id' => $allowedSite->id,
                'action' => 'sync_devices',
                'status' => IntegrationSyncLog::STATUS_SUCCESS,
                'started_at' => now()->subMinutes(2),
            ]);
            $hiddenLog = IntegrationSyncLog::query()->create([
                'provider' => $provider,
                'site_id' => $hiddenSite->id,
                'action' => 'pull_health',
                'status' => IntegrationSyncLog::STATUS_FAILED,
                'started_at' => now()->subMinute(),
            ]);

            $this->actingAs($manager)
                ->get("/security-devices/integrations/{$provider}")
                ->assertOk()
                ->assertInertia(function ($page) use ($allowedLog, $applicationLog, $hiddenLog): void {
                    $ids = collect($page->toArray()['props']['syncLogs'])->pluck('id');

                    $this->assertEqualsCanonicalizing([$applicationLog->id, $allowedLog->id], $ids->all());
                    $this->assertNotContains($hiddenLog->id, $ids);
                });
        }
    }

    public function test_queclink_hub_and_frame_feed_follow_canonical_device_and_site_access(): void
    {
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $manager = $this->manager($allowedSite);
        $assetTrackerManager = Permission::query()->where('key', 'assets.trackers.manage')->value('id');
        $this->assertNotNull($assetTrackerManager);
        $manager->permissionOverrides()->attach($assetTrackerManager, ['allowed' => false]);
        $viewUnassigned = Permission::query()->where('key', 'securityDevices.devices.viewUnassigned')->value('id');
        $this->assertNotNull($viewUnassigned);
        $manager->permissionOverrides()->attach($viewUnassigned, ['allowed' => false]);

        $allowedDevice = Device::factory()->tracking()->create(['provider' => 'queclink']);
        $hiddenDevice = Device::factory()->tracking()->create(['provider' => 'queclink']);
        foreach ([[$allowedDevice, $allowedSite], [$hiddenDevice, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }

        $allowedTracker = QueclinkDevice::query()->create([
            'device_id' => $allowedDevice->id,
            'imei' => '860000000000101',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
        ]);
        $hiddenTracker = QueclinkDevice::query()->create([
            'device_id' => $hiddenDevice->id,
            'imei' => '860000000000102',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
        ]);
        $pendingTracker = QueclinkDevice::query()->create([
            'imei' => '860000000000103',
            'status' => QueclinkDevice::STATUS_PENDING,
            'connection_state' => QueclinkDevice::CONN_DISCONNECTED,
        ]);

        foreach ([$allowedTracker, $hiddenTracker, $pendingTracker] as $tracker) {
            QueclinkRawFrame::query()->create([
                'queclink_device_id' => $tracker->id,
                'imei' => $tracker->imei,
                'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
                'frame_type' => QueclinkRawFrame::FRAME_RESP,
                'command_word' => 'GTHBD',
                'raw_frame' => 'RAW-FRAME-'.$tracker->id,
                'parse_ok' => true,
            ]);
        }

        $this->actingAs($manager)
            ->get('/security-devices/integrations/queclink')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices.total', 1)
                ->where('devices.counts.paired', 1)
                ->where('devices.counts.pending', 0)
                ->where('devices.paired.0.id', $allowedTracker->id)
                ->where('listener.connected_count', 1)
                ->where('statistics.frames_last_hour', 1));

        $this->actingAs($manager)
            ->get('/security-devices/integrations/queclink/frames')
            ->assertOk()
            ->assertJsonCount(1, 'frames');

        $manager->permissionOverrides()->updateExistingPivot($viewUnassigned, ['allowed' => true]);
        $manager->unsetRelation('permissionOverrides');

        $this->actingAs($manager)
            ->get('/security-devices/integrations/queclink')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices.total', 2)
                ->where('devices.counts.pending', 1)
                ->where('devices.pending.0.id', $pendingTracker->id)
                ->where('statistics.frames_last_hour', 2));
    }

    public function test_queclink_audit_records_provider_site_device_actor_and_bounded_outcome(): void
    {
        $site = Site::factory()->create([]);
        $manager = $this->manager($site);
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'queclink',
            'secret_encrypted' => Crypt::encryptString('provider-key'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        $canonicalDevice = Device::factory()->tracking()->create([
            'provider' => 'queclink',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonicalDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $tracker = QueclinkDevice::query()->create([
            'device_id' => $canonicalDevice->id,
            'imei' => '860000000000201',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($manager)
            ->post("/security-devices/integrations/queclink/devices/{$tracker->id}/reject")
            ->assertRedirect();

        $this->assertDatabaseHas('queclink_audit_events', [
            'provider_connection_id' => $connection->id,
            'site_id' => $site->id,
            'canonical_device_id' => $canonicalDevice->id,
            'queclink_device_id' => $tracker->id,
            'user_id' => $manager->id,
            'event_type' => 'reject',
            'outcome' => 'succeeded',
            'raw_command' => null,
            'notes' => null,
        ]);
    }

    public function test_queclink_claim_and_release_use_canonical_asset_link_history(): void
    {
        $site = Site::factory()->create([]);
        $manager = $this->manager($site);
        $permissionIds = Permission::query()
            ->whereIn('key', ['assets.viewAny', 'fleet.viewAny'])
            ->pluck('id');
        $this->assertCount(2, $permissionIds);
        $manager->permissionOverrides()->syncWithoutDetaching(
            $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
        );
        $asset = Asset::factory()->create([
            'site_id' => $site->id,
            'category' => 'vehicle',
        ]);
        $tracker = QueclinkDevice::query()->create([
            'imei' => '860000000000202',
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GV500CG',
        ]);

        $this->actingAs($manager)
            ->post("/security-devices/integrations/queclink/devices/{$tracker->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertRedirect();

        $canonicalDeviceId = (int) $tracker->fresh()->device_id;
        $link = DeviceAssetLink::query()
            ->where('device_id', $canonicalDeviceId)
            ->where('asset_id', $asset->id)
            ->sole();
        $this->assertNull($link->unlinked_at);
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $canonicalDeviceId,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $asset->id,
            'released_at' => null,
        ]);

        $this->actingAs($manager)
            ->post("/security-devices/integrations/queclink/devices/{$tracker->id}/release")
            ->assertRedirect();

        $this->assertNotNull($link->fresh()->unlinked_at);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $tracker->fresh()->status);
        $this->assertNull($tracker->fresh()->device_id);
        $this->assertDatabaseHas('queclink_audit_events', [
            'site_id' => $site->id,
            'canonical_device_id' => $canonicalDeviceId,
            'queclink_device_id' => $tracker->id,
            'event_type' => 'release',
            'outcome' => 'succeeded',
        ]);
    }

    private function manager(
        ?Site $site = null,
    ): User {
        $user = User::factory()->create([

            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());

        $permissionId = Permission::query()
            ->where('key', 'securityDevices.integrations.manage')
            ->value('id');
        $this->assertNotNull($permissionId);
        $user->permissionOverrides()->attach($permissionId, ['allowed' => true]);

        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
        }

        return $user;
    }
}
