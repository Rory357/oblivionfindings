<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Integration\IntegrationProviderConnection;
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
use Illuminate\Support\Facades\DB;
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
        $manager = $this->manager(organizationId: 42);
        DB::table('integration_tenant_secrets')->insert([
            'tenant_id' => 77,
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('RAW-PROVIDER-SECRET'),
            'secret_last4' => '0042',
            'status' => 'connected',
            'config' => json_encode([
                'base_url' => 'https://private-provider.example.test',
                'api_token' => 'RAW-PROVIDER-CONFIG',
            ], JSON_THROW_ON_ERROR),
            'last_error' => 'Bearer RAW-PROVIDER-ERROR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($manager)->get('/security-devices/integrations/milesight');

        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            $this->assertArrayHasKey('providerConnection', $props);
            $this->assertArrayNotHasKey('tenantSecret', $props);
            $this->assertSame('connected', $props['providerConnection']['status']);
            $this->assertSame('0042', $props['providerConnection']['secret_last4']);
            $this->assertTrue($props['providerConnection']['endpoint_configured']);

            $encoded = json_encode($props, JSON_THROW_ON_ERROR);
            foreach (['RAW-PROVIDER-', 'private-provider.example.test'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        });
    }

    public function test_provider_identity_is_globally_unique_even_when_legacy_partition_values_differ(): void
    {
        IntegrationProviderConnection::query()->create([
            'tenant_id' => 11,
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('first-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
        ]);

        $this->expectException(QueryException::class);

        IntegrationProviderConnection::query()->create([
            'tenant_id' => 12,
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('second-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_saving_a_key_updates_the_single_provider_connection_instead_of_creating_an_actor_partition(): void
    {
        $manager = $this->manager(organizationId: 42);
        $connectionId = DB::table('integration_tenant_secrets')->insertGetId([
            'tenant_id' => 77,
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('old-secret'),
            'secret_last4' => '0000',
            'status' => 'connected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->post('/security-devices/integrations/milesight/key', [
            'api_key' => 'replacement-key-9876',
            'base_url' => 'https://milesight.example.test',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('integration_tenant_secrets')->where('provider', 'milesight')->count());
        $this->assertDatabaseHas('integration_tenant_secrets', [
            'id' => $connectionId,
            'provider' => 'milesight',
            'secret_last4' => '9876',
            'status' => 'disconnected',
        ]);
        $this->assertSame(1, DB::table('integrations')->where('provider', 'milesight')->count());
        $this->assertDatabaseMissing('integration_tenant_secrets', [
            'provider' => 'milesight',
            'tenant_id' => 42,
        ]);
    }

    public function test_site_credentials_follow_canonical_site_access_and_ignore_legacy_partition_values(): void
    {
        $allowedSite = Site::factory()->create(['tenant_id' => 701, 'name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['tenant_id' => 702, 'name' => 'Hidden Site']);
        $manager = $this->manager($allowedSite, organizationId: 42, profileTenantId: 801);

        IntegrationSiteSecret::query()->create([
            'tenant_id' => 901,
            'site_id' => $allowedSite->id,
            'provider' => 'milesight',
            'capability' => 'network',
            'secret_encrypted' => Crypt::encryptString('allowed-secret'),
            'is_enabled' => true,
            'last_tested_at' => now(),
        ]);
        IntegrationSiteSecret::query()->create([
            'tenant_id' => 42,
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

    public function test_provider_sync_history_only_includes_application_and_accessible_site_runs(): void
    {
        $allowedSite = Site::factory()->create(['tenant_id' => 501]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 502]);
        $manager = $this->manager($allowedSite, organizationId: 42, profileTenantId: 601);

        foreach (['milesight'] as $provider) {
            $applicationLog = IntegrationSyncLog::query()->create([
                'tenant_id' => 701,
                'provider' => $provider,
                'site_id' => null,
                'action' => 'discover_sites',
                'status' => IntegrationSyncLog::STATUS_SUCCESS,
                'started_at' => now()->subMinutes(3),
            ]);
            $allowedLog = IntegrationSyncLog::query()->create([
                'tenant_id' => 702,
                'provider' => $provider,
                'site_id' => $allowedSite->id,
                'action' => 'sync_devices',
                'status' => IntegrationSyncLog::STATUS_SUCCESS,
                'started_at' => now()->subMinutes(2),
            ]);
            $hiddenLog = IntegrationSyncLog::query()->create([
                'tenant_id' => 42,
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
        $allowedSite = Site::factory()->create(['tenant_id' => 501]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 502]);
        $manager = $this->manager($allowedSite, organizationId: 42, profileTenantId: 601);
        $assetTrackerManager = Permission::query()->where('key', 'assets.trackers.manage')->value('id');
        $this->assertNotNull($assetTrackerManager);
        $manager->permissionOverrides()->attach($assetTrackerManager, ['allowed' => false]);
        $viewUnassigned = Permission::query()->where('key', 'securityDevices.devices.viewUnassigned')->value('id');
        $this->assertNotNull($viewUnassigned);
        $manager->permissionOverrides()->attach($viewUnassigned, ['allowed' => false]);

        $allowedDevice = Device::factory()->tracking()->create(['tenant_id' => 701, 'provider' => 'queclink']);
        $hiddenDevice = Device::factory()->tracking()->create(['tenant_id' => 42, 'provider' => 'queclink']);
        foreach ([[$allowedDevice, $allowedSite], [$hiddenDevice, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }

        $allowedTracker = QueclinkDevice::query()->create([
            'tenant_id' => 801,
            'device_id' => $allowedDevice->id,
            'imei' => '860000000000101',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
        ]);
        $hiddenTracker = QueclinkDevice::query()->create([
            'tenant_id' => 42,
            'device_id' => $hiddenDevice->id,
            'imei' => '860000000000102',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
        ]);
        $pendingTracker = QueclinkDevice::query()->create([
            'tenant_id' => 999,
            'imei' => '860000000000103',
            'status' => QueclinkDevice::STATUS_PENDING,
            'connection_state' => QueclinkDevice::CONN_DISCONNECTED,
        ]);

        foreach ([$allowedTracker, $hiddenTracker, $pendingTracker] as $tracker) {
            QueclinkRawFrame::query()->create([
                'tenant_id' => 999,
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
        $site = Site::factory()->create(['tenant_id' => 501]);
        $manager = $this->manager($site, organizationId: 42, profileTenantId: 601);
        $connection = IntegrationProviderConnection::query()->create([
            'tenant_id' => 701,
            'provider' => 'queclink',
            'secret_encrypted' => Crypt::encryptString('provider-key'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        $canonicalDevice = Device::factory()->tracking()->create([
            'tenant_id' => 801,
            'provider' => 'queclink',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonicalDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $tracker = QueclinkDevice::query()->create([
            'tenant_id' => 901,
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
        $site = Site::factory()->create(['tenant_id' => 501]);
        $manager = $this->manager($site, organizationId: 42, profileTenantId: 601);
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
            'tenant_id' => 901,
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
        ?int $organizationId = null,
        int $profileTenantId = 1,
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organizationId,
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
                'tenant_id' => $profileTenantId,
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
        }

        return $user;
    }
}
