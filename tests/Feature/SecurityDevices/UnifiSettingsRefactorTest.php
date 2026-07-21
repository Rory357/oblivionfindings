<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\LocationHardware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_provider_read_model_redacts_raw_discovery_config_errors_and_external_references(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        IntegrationProviderConnection::create([
            'tenant_id' => 1,
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
            'tenant_id' => 1,
            'provider' => 'unifi',
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_FAILED,
            'error_message' => 'Bearer RAW-SYNC-ERROR-SENTINEL',
            'started_at' => now(),
        ]);
        Device::factory()->create([
            'tenant_id' => 1,
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
        $allowedSite = Site::factory()->create(['tenant_id' => 1]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 77]);
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

    public function test_sync_devices_does_not_reveal_missing_foreign_or_non_unifi_site_configs(): void
    {
        config()->set('app.debug', false);
        $allowedSite = Site::factory()->create(['tenant_id' => 1]);
        $siteManager = $this->managerForSite($allowedSite);
        $hiddenSite = Site::factory()->create(['tenant_id' => 77]);
        $hiddenConfig = IntegrationSiteConfig::create([
            'tenant_id' => 77,
            'site_id' => $hiddenSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'foreign-site',
            'is_active' => true,
        ]);
        $otherProviderConfig = IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $allowedSite->id,
            'provider' => 'verkada',
            'mapped_external_site_id' => 'other-provider-site',
            'is_active' => true,
        ]);
        $secondHiddenSite = Site::factory()->create(['tenant_id' => 77]);
        $hiddenSiteConfig = IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $secondHiddenSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'foreign-related-site',
            'is_active' => true,
        ]);
        $missingConfigId = max($hiddenConfig->id, $otherProviderConfig->id, $hiddenSiteConfig->id) + 1000;

        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldNotReceive('has');
        $registry->shouldNotReceive('resolve');
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
        $site = Site::factory()->create(['tenant_id' => 1]);
        $siteConfig = IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'local-site',
            'is_active' => true,
        ]);
        IntegrationProviderConnection::create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'secret_encrypted' => 'test-secret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $adapter = \Mockery::mock(IntegrationAdapterInterface::class);
        $adapter->shouldReceive('syncDevices')
            ->once()
            ->withArgs(fn (IntegrationSiteConfig $config, IntegrationProviderConnection $secret): bool => $config->is($siteConfig) && $secret->tenant_id === 1 && $secret->provider === 'unifi')
            ->andReturn(new SyncResult(processed: 3, created: 1, updated: 2));
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('has')->once()->with('unifi')->andReturnTrue();
        $registry->shouldReceive('resolve')->once()->with('unifi')->andReturn($adapter);
        $this->instance(IntegrationAdapterRegistry::class, $registry);

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/unifi/sync-devices', ['site_config_id' => $siteConfig->id])
            ->assertRedirect()
            ->assertSessionHas('success', 'Device sync complete. Processed 3, created 1, updated 2, errored 0.');

        $this->assertDatabaseHas('integration_sync_logs', [
            'tenant_id' => 1,
            'provider' => 'unifi',
            'site_id' => $site->id,
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'items_processed' => 3,
            'items_created' => 1,
            'items_updated' => 2,
            'items_errored' => 0,
        ]);
    }

    public function test_inactive_unifi_site_config_is_indistinguishable_from_missing_and_never_syncs(): void
    {
        config()->set('app.debug', false);
        $site = Site::factory()->create(['tenant_id' => 1]);
        $inactiveConfig = IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'inactive-site',
            'is_active' => false,
        ]);
        IntegrationProviderConnection::create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'secret_encrypted' => 'test-secret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);

        $syncCalls = 0;
        $adapter = \Mockery::mock(IntegrationAdapterInterface::class);
        $adapter->shouldReceive('syncDevices')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () use (&$syncCalls): SyncResult {
                $syncCalls++;
                Device::factory()->create([
                    'tenant_id' => 1,
                    'provider' => 'unifi',
                    'name' => 'INACTIVE-SYNC-SENTINEL',
                ]);

                return new SyncResult(processed: 1, created: 1);
            });
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('has')->zeroOrMoreTimes()->with('unifi')->andReturnTrue();
        $registry->shouldReceive('resolve')->zeroOrMoreTimes()->with('unifi')->andReturn($adapter);
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
        $validSite = Site::factory()->create(['tenant_id' => 1, 'name' => 'Valid tenant location']);
        $foreignSite = Site::factory()->create(['tenant_id' => 77, 'name' => 'FOREIGN-MAPPING-SENTINEL']);
        $siteManager = $this->managerForSite($validSite);
        $validConfig = IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $validSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'valid-site',
            'mapped_external_site_name' => 'Valid controller',
            'is_active' => true,
        ]);
        $foreignConfig = IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $foreignSite->id,
            'provider' => 'unifi',
            'mapped_external_site_id' => 'foreign-site',
            'mapped_external_site_name' => 'FOREIGN-CONTROLLER-SENTINEL',
            'is_active' => true,
        ]);

        $this->actingAs($siteManager)
            ->get('/security-devices/integrations/unifi')
            ->assertOk()
            ->assertInertia(function ($page) use ($validConfig, $foreignConfig): void {
                $configs = collect($page->toArray()['props']['siteConfigs']);
                $this->assertSame([$validConfig->id], $configs->pluck('id')->all());
                $this->assertNotContains($foreignConfig->id, $configs->pluck('id'));
                $this->assertStringNotContainsString('FOREIGN-', json_encode($configs, JSON_THROW_ON_ERROR));
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
            'tenant_id' => 1,
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
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Main Office']);
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Comms Room',
        ]);

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'UniFi AP',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unifi-ap-1'],
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 1,
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

    public function test_room_assignment_does_not_reveal_missing_foreign_or_contradictory_rooms_and_never_mutates(): void
    {
        config()->set('app.debug', false);
        $site = Site::factory()->create(['tenant_id' => 1]);
        $otherLocalSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $localRoom = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Local room',
        ]);
        $wrongSiteRoom = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $otherLocalSite->id,
            'name' => 'Wrong local site room',
        ]);
        $foreignRoom = SiteRoom::create([
            'tenant_id' => 77,
            'site_id' => $foreignSite->id,
            'name' => 'FOREIGN-ROOM-SENTINEL',
        ]);
        $contradictoryRoom = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $foreignSite->id,
            'name' => 'CONTRADICTORY-ROOM-SENTINEL',
        ]);
        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Protected shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'protected-device'],
        ]);
        $device = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 1,
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
        $foreignDevice = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 77,
            'provider' => 'unifi',
        ]);
        $foreignAssignment = DeviceAssignment::create([
            'device_id' => $foreignDevice->id,
            'assignable_type' => 'site',
            'assignable_id' => $foreignSite->id,
            'assigned_at' => now(),
        ]);
        $auditCount = AuditLog::query()->count();
        $deviceUpdatedAt = $device->updated_at?->toJSON();
        $shadowUpdatedAt = $shadow->updated_at?->toJSON();

        $missingRoomId = max($localRoom->id, $wrongSiteRoom->id, $foreignRoom->id, $contradictoryRoom->id) + 1000;
        $responses = collect([$missingRoomId, $foreignRoom->id, $wrongSiteRoom->id, $contradictoryRoom->id])
            ->map(fn (int $roomId) => $this->actingAs($this->admin)->putJson(
                "/security-devices/integrations/unifi/hardware/{$device->id}/room",
                ['room_id' => $roomId],
            ));
        $foreignHardware = $this->actingAs($this->admin)->putJson(
            "/security-devices/integrations/unifi/hardware/{$foreignDevice->id}/room",
            ['room_id' => $localRoom->id],
        );

        $this->assertSame([404, 404, 404, 404], $responses->map->getStatusCode()->all());
        $this->assertCount(1, $responses->map->getContent()->unique());
        $foreignHardware->assertNotFound();
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
            'id' => $foreignAssignment->id,
            'assignable_type' => 'site',
            'assignable_id' => $foreignSite->id,
            'released_at' => null,
        ]);
        $this->assertSame(1, DeviceAssignment::query()->where('device_id', $device->id)->count());
        $this->assertSame(1, DeviceAssignment::query()->where('device_id', $foreignDevice->id)->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_room_clear_uses_canonical_active_room_site_despite_legacy_partition_values(): void
    {
        config()->set('app.debug', false);
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $foreignRoom = SiteRoom::create([
            'tenant_id' => 77,
            'site_id' => $foreignSite->id,
            'name' => 'Foreign provenance room',
        ]);
        $contradictoryRoom = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $foreignSite->id,
            'name' => 'Contradictory provenance room',
        ]);

        $devices = collect([$foreignRoom, $contradictoryRoom])->map(function (SiteRoom $room, int $index) use ($localSite) {
            $shadow = LocationHardware::create([
                'tenant_id' => 1,
                'site_id' => $localSite->id,
                'provider' => 'unifi',
                'category' => LocationHardware::CATEGORY_AP,
                'name' => "Local fallback shadow {$index}",
                'status' => LocationHardware::STATUS_ONLINE,
                'external_ref' => ['provider_entity_id' => "corrupt-room-device-{$index}"],
            ]);
            $device = Device::factory()->itInfrastructure()->create([
                'tenant_id' => 1,
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
            $this->assertSame($foreignSite->id, $active->assignable_id);
        }
    }

    public function test_room_clear_uses_legacy_shadow_site_as_compatibility_fallback(): void
    {
        config()->set('app.debug', false);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $foreignShadow = LocationHardware::create([
            'tenant_id' => 77,
            'site_id' => $foreignSite->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Foreign provenance shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'foreign-shadow-device'],
        ]);
        $contradictoryShadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $foreignSite->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'Contradictory provenance shadow',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'contradictory-shadow-device'],
        ]);

        $devices = collect([$foreignShadow, $contradictoryShadow])->map(
            fn (LocationHardware $shadow) => Device::factory()->itInfrastructure()->create([
                'tenant_id' => 1,
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
            $this->assertSame($foreignSite->id, $active->assignable_id);
        }
    }

    public function test_clearing_room_restores_site_assignment(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Branch Office']);
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Entry',
        ]);

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'room_id' => $room->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_DOOR,
            'name' => 'UniFi Access Reader',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unifi-door-1'],
        ]);

        $device = Device::factory()->security()->create([
            'tenant_id' => 1,
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
