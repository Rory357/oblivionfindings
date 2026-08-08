<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceGroupMember;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SafeOperationalData;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create(['approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_user_without_any_settings_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $this->actingAs($user)->get('/security-devices/settings')->assertForbidden();
    }

    public function test_command_administrator_receives_the_shared_navigation_capability_and_can_open_settings(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        foreach (['securityDevices.viewAny', 'securityDevices.commands.admin'] as $permissionKey) {
            $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        $this->actingAs($user)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.can.securityDevices.viewAny', true)
                ->where('auth.can.securityDevices.commandsAdmin', true)
                ->where('auth.can.securityDevices.groupsManage', false)
                ->where('auth.can.securityDevices.reportsView', false));
    }

    public function test_settings_projects_real_safe_defaults_profiles_exceptions_and_feature_support(): void
    {
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'RAW-SECRET',
            'secret_last4' => '0042',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => [
                'refresh_interval_minutes' => 15,
                'alert_motion_events' => true,
                'api_token' => 'RAW-CONFIG-TOKEN',
                'discovered_sites' => [['id' => 'RAW-SITE-ID']],
            ],
        ]);
        MonitoringProfile::factory()->create([
            'name' => 'Critical infrastructure',
            'description' => 'Fast checks for core paths',
            'interval_seconds' => 60,
            'failure_confirmations' => 3,
            'recovery_confirmations' => 2,
            'stale_after_seconds' => 300,
            'is_active' => true,
        ]);
        MonitoringProfile::factory()->create(['name' => 'Unrelated profile']);
        Device::factory()->create(['provider' => 'unifi']);
        Device::factory()->create(['provider' => 'unifi']);

        $this->actingAs($this->admin)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $this->assertSame('not_configured', $props['classificationDefaults']['state']);
                $this->assertSame([], $props['classificationDefaults']['values']);
                $this->assertEquals([
                    'refresh_interval_minutes' => 15,
                    'alert_motion_events' => true,
                ], $props['providerOperationalDefaults'][0]['values']);
                $this->assertSame(['Critical infrastructure', 'Unrelated profile'], collect($props['monitoringProfiles'])->pluck('name')->all());
                $this->assertSame(2, $props['dataQuality']['unassigned_devices']);
                $this->assertSame('supported', $props['featureSupport']['discovery_candidates']['state']);
                $this->assertSame('read_only_append_only_application_evidence', $props['audit']['evidence_state']);
                $encoded = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('RAW-', $encoded);
                $this->assertStringNotContainsString('secret_encrypted', $encoded);
            });
    }

    public function test_audit_is_report_permission_only_whitelisted_record_scoped_and_safely_projected(): void
    {
        $device = Device::factory()->create([]);
        $client = Client::factory()->create();
        $unrelated = Device::factory()->create([]);
        AuditLog::query()->delete();

        AuditLog::create([

            'user_id' => $this->admin->id,
            'action' => 'device.update',
            'auditable_type' => Device::class,
            'auditable_id' => $device->id,
            'meta' => ['fields' => ['name', 'secret_encrypted', 'external_ref'], 'before' => ['name' => 'RAW-BEFORE'], 'after' => ['name' => 'RAW-AFTER']],
            'ip_address' => '10.1.2.3',
            'user_agent' => 'RAW-AGENT',
        ]);
        AuditLog::create([

            'action' => 'client.update',
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'meta' => ['fields' => ['name']],
        ]);
        AuditLog::create([

            'action' => 'device.update',
            'auditable_type' => Device::class,
            'auditable_id' => $unrelated->id,
            'meta' => ['fields' => ['name']],
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $audit = $page->toArray()['props']['audit'];
                $this->assertCount(2, $audit['entries']);
                $this->assertTrue(collect($audit['entries'])->every(
                    fn (array $entry): bool => $entry['fields'] === ['name'],
                ));
                $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
                foreach (['RAW-', '10.1.2.3', 'secret_encrypted', 'external_ref', 'client.update'] as $sentinel) {
                    $this->assertStringNotContainsString($sentinel, $encoded);
                }
            });

        $reports = Permission::query()->where('key', 'securityDevices.reports.view')->firstOrFail();
        $this->admin->permissionOverrides()->attach($reports->id, ['allowed' => false]);
        $this->admin->unsetRelation('permissionOverrides');
        $this->admin->unsetRelation('roles');
        $this->actingAs($this->admin)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('audit.entries', [])->where('audit.visible', false));
    }

    public function test_integration_secret_mutation_audit_never_persists_reusable_secret_content(): void
    {
        $secret = IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'RAW-CREATE-SECRET',
            'secret_last4' => '1234',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => ['token' => 'RAW-CONFIG'],
        ]);
        $secret->update([
            'secret_encrypted' => 'RAW-ROTATED-SECRET',
            'config' => ['token' => 'RAW-UPDATED-CONFIG'],
        ]);

        $encoded = AuditLog::query()
            ->where('auditable_type', IntegrationProviderConnection::class)
            ->pluck('meta')
            ->toJson();

        foreach (['RAW-', 'secret_encrypted', 'config'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }
    }

    public function test_shared_audit_policy_redacts_technical_payloads_but_preserves_safe_state_evidence(): void
    {
        $site = Site::factory()->create([]);
        $device = Device::factory()->create([
            'external_ref' => ['provider_entity_id' => 'RAW-DEVICE-REF'],
            'config' => ['token' => 'RAW-DEVICE-CONFIG'],
            'meta' => ['payload' => 'RAW-DEVICE-META'],
        ]);
        $device->update(['status' => 'offline', 'config' => ['token' => 'RAW-UPDATED-CONFIG']]);
        Integration::create([
            'provider' => 'unifi', 'display_name' => 'UniFi',
            'status' => Integration::STATUS_ERROR, 'config' => ['url' => 'https://RAW-INTEGRATION.test'],
            'last_error' => 'Bearer RAW-INTEGRATION-ERROR',
        ]);
        IntegrationSiteConfig::create([
            'site_id' => $site->id, 'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'RAW-EXTERNAL-ID',
            'overrides' => ['credential' => 'RAW-OVERRIDE'], 'is_active' => true,
        ]);
        IntegrationSyncLog::create([
            'site_id' => $site->id, 'provider' => 'unifi',
            'action' => 'sync_devices', 'status' => IntegrationSyncLog::STATUS_FAILED,
            'error_message' => 'https://RAW-SYNC.test/?token=secret', 'started_at' => now(),
        ]);
        IntegrationEvent::create([
            'site_id' => $site->id, 'canonical_device_id' => $device->id,
            'provider' => 'unifi', 'source_app' => 'protect', 'source_event_id' => 'RAW-EVENT-ID',
            'occurred_at' => now(), 'received_at' => now(), 'severity' => 'warn', 'event_type' => 'motion',
            'normalized_payload' => ['token' => 'RAW-NORMALIZED'], 'raw_payload' => ['frame' => 'RAW-PAYLOAD'],
        ]);

        $encoded = AuditLog::query()->pluck('meta')->toJson();
        foreach (['RAW-', 'external_ref', 'config', 'meta', 'mapped_external_site_id', 'overrides', 'error_message', 'normalized_payload', 'raw_payload'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }
        $this->assertStringContainsString('status', $encoded);
        $this->assertStringContainsString('offline', $encoded);
        $this->assertStringContainsString('scope', $encoded);
    }

    public function test_direct_audit_logger_calls_are_sanitized_for_protected_models(): void
    {
        $site = Site::factory()->create([]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $device = Device::factory()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        AuditLogger::logOrFail('device.update', $device, [

            'actor_id' => $this->admin->id,
            'client_id' => $client->id,
            'fields' => ['status', 'config', 'raw_payload', 'last_error'],
            'before' => [
                'status' => 'active',
                'config' => ['token' => 'RAW-NESTED-TOKEN'],
                'nested' => ['url' => 'https://RAW-BEFORE.test'],
            ],
            'after' => [
                'status' => 'offline',
                'error_message' => 'Bearer RAW-AFTER-ERROR',
                'payload' => ['remote_target' => 'RAW-REMOTE-ID'],
            ],
            'scope' => [

                'device_id' => $device->id,
                'site_ids' => [$site->id],
                'remote_target_id' => 'RAW-TARGET-ID',
            ],
            'secret' => 'RAW-TOP-LEVEL-SECRET',
            'arbitrary' => ['command' => 'RAW-COMMAND'],
        ]);

        $audit = AuditLog::query()->where('action', 'device.update')->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertSame(['status'], data_get($audit->meta, 'fields'));
        $this->assertSame(['status' => 'active'], data_get($audit->meta, 'before'));
        $this->assertSame(['status' => 'offline'], data_get($audit->meta, 'after'));
        $this->assertSame([$site->id], data_get($audit->meta, 'scope.site_ids'));
        $encoded = json_encode($audit->meta, JSON_THROW_ON_ERROR);
        foreach (['RAW-', 'secret', 'token', 'config', 'payload', 'error', 'url', 'remote', 'target', 'command', 'arbitrary'] as $sentinel) {
            $this->assertStringNotContainsString(strtolower($sentinel), strtolower($encoded));
        }
    }

    public function test_protected_audit_logger_derives_canonical_site_and_device_scope_instead_of_trusting_callers(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $device = Device::factory()->create([]);
        $unrelatedDevice = Device::factory()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $canonicalSite->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('device.update', $device, [

            'fields' => ['status'],
            'after' => ['status' => 'offline'],
            'scope' => [

                'site_id' => $unrelatedSite->id,
                'device_id' => $unrelatedDevice->id,
                'site_ids' => [$unrelatedSite->id],
                'device_ids' => [$unrelatedDevice->id],
            ],
        ]);

        $audit = AuditLog::query()->where('action', 'device.update')->sole();
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertNull(data_get($audit->meta, 'scope.device_ids'));
        $this->assertStringNotContainsString((string) $unrelatedDevice->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $rightfulViewer = $this->siteRestrictedViewer($canonicalSite);
        $unrelatedViewer = $this->siteRestrictedViewer($unrelatedSite);

        $this->actingAs($rightfulViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($device): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$device->id));
        });
        $this->actingAs($unrelatedViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($device): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$device->id));
        });
    }

    public function test_device_asset_link_audit_cannot_be_rehomed_by_unrelated_caller_context(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $device = Device::factory()->create([]);
        $unrelatedDevice = Device::factory()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $canonicalSite->id,
            'assigned_at' => now(),
        ]);
        $unrelatedClient = Client::factory()->create(['site_id' => $unrelatedSite->id]);
        $unrelatedActor = User::factory()->create(['approved_at' => now()]);
        $link = DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => Asset::factory()->create()->id,
            'link_type' => 'primary',
            'linked_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassetlink.update', $link, [

            'client_id' => $unrelatedClient->id,
            'actor_id' => $unrelatedActor->id,
            'fields' => ['link_type'],
            'scope' => [

                'site_id' => $unrelatedSite->id,
                'site_ids' => [$unrelatedSite->id],
                'device_id' => $unrelatedDevice->id,
            ],
        ]);

        $audit = AuditLog::query()->where('action', 'deviceassetlink.update')->sole();
        $this->assertSame($unrelatedActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertStringNotContainsString('"client_id":'.$unrelatedClient->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"site_id":'.$unrelatedSite->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"device_id":'.$unrelatedDevice->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $rightfulViewer = $this->siteRestrictedViewer($canonicalSite);
        $hiddenViewer = $this->siteRestrictedViewer($hiddenSite);
        $unrelatedViewer = $this->siteRestrictedViewer($unrelatedSite);

        $this->actingAs($rightfulViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($link): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$link->id));
        });
        $this->actingAs($hiddenViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($link): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$link->id));
        });
        $this->actingAs($unrelatedViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($link): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$link->id));
        });
    }

    public function test_device_maintenance_audit_cannot_be_rehomed_and_has_no_actor_fallback_without_a_canonical_parent(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $device = Device::factory()->create([]);
        $unrelatedDevice = Device::factory()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $canonicalSite->id,
            'assigned_at' => now(),
        ]);
        $unrelatedClient = Client::factory()->create(['site_id' => $unrelatedSite->id]);
        $unrelatedActor = User::factory()->create(['approved_at' => now()]);
        $record = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Canonical inspection',
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('devicemaintenancerecord.update', $record, [

            'client_id' => $unrelatedClient->id,
            'actor_id' => $unrelatedActor->id,
            'fields' => ['status'],
            'after' => ['status' => 'completed'],
            'scope' => [

                'site_id' => $unrelatedSite->id,
                'site_ids' => [$unrelatedSite->id],
                'device_id' => $unrelatedDevice->id,
            ],
        ]);

        $audit = AuditLog::query()->where('action', 'devicemaintenancerecord.update')->sole();
        $this->assertSame($unrelatedActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertStringNotContainsString('"client_id":'.$unrelatedClient->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"site_id":'.$unrelatedSite->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"device_id":'.$unrelatedDevice->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $rightfulViewer = $this->siteRestrictedViewer($canonicalSite);
        $unrelatedViewer = $this->siteRestrictedViewer($unrelatedSite);
        $this->actingAs($rightfulViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($record): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$record->id));
        });
        $this->actingAs($unrelatedViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($record): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$record->id));
        });

        $orphan = new DeviceMaintenanceRecord(['device_id' => 999999]);
        AuditLogger::logOrFail('devicemaintenancerecord.update', $orphan, [

            'client_id' => $unrelatedClient->id,
            'actor_id' => $unrelatedActor->id,
            'scope' => ['device_id' => $unrelatedDevice->id],
        ]);
        $orphanAudit = AuditLog::query()->whereNull('auditable_id')->where('action', 'devicemaintenancerecord.update')->sole();
        $this->assertNull($orphanAudit->client_id);
        $this->assertNull(data_get($orphanAudit->meta, 'scope'));
    }

    public function test_protected_child_model_survey_uses_only_unambiguous_canonical_device_relations(): void
    {
        $site = Site::factory()->create([]);
        $firstDevice = Device::factory()->create([]);
        $secondDevice = Device::factory()->create([]);
        $unrelatedDevice = Device::factory()->create([]);
        foreach ([$firstDevice, $secondDevice] as $device) {
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }

        foreach ([
            new DeviceDocument(['device_id' => $firstDevice->id]),
            new DeviceEvent(['device_id' => $firstDevice->id]),
        ] as $child) {
            $scope = SafeOperationalData::auditScope($child);

            $this->assertSame($firstDevice->id, data_get($scope, 'device_id'));
            $this->assertSame([$site->id], data_get($scope, 'site_ids'));
        }

        $group = DeviceGroup::create(['name' => 'Canonical group', 'type' => 'manual']);
        $memberScope = SafeOperationalData::auditScope(new DeviceGroupMember([
            'device_group_id' => $group->id,
            'device_id' => $firstDevice->id,
        ]));

        $this->assertSame($firstDevice->id, data_get($memberScope, 'device_id'));
        $this->assertSame([], SafeOperationalData::auditScope(new DeviceGroupMember([
            'device_group_id' => 999999,
            'device_id' => $firstDevice->id,
        ])));

        $relationshipScope = SafeOperationalData::auditScope(new DeviceRelationship([
            'parent_device_id' => $firstDevice->id,
            'child_device_id' => $secondDevice->id,
            'relationship_type' => 'connected_to',
        ]));

        $this->assertEqualsCanonicalizing([$firstDevice->id, $secondDevice->id], data_get($relationshipScope, 'device_ids'));
        $this->assertSame([$site->id], data_get($relationshipScope, 'site_ids'));
        $this->assertSame([], SafeOperationalData::auditScope(new DeviceRelationship([
            'parent_device_id' => $firstDevice->id,
            'child_device_id' => $unrelatedDevice->id,
            'relationship_type' => 'connected_to',
        ])));
    }

    public function test_orphan_device_assignment_audit_discards_fabricated_device_and_caller_scope(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $unrelatedDevice = Device::factory()->create([]);
        $unrelatedClient = Client::factory()->create(['site_id' => $unrelatedSite->id]);
        $unrelatedActor = User::factory()->create(['approved_at' => now()]);
        $orphan = new DeviceAssignment([
            'device_id' => 999999,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $unrelatedSite->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $orphan, [

            'client_id' => $unrelatedClient->id,
            'actor_id' => $unrelatedActor->id,
            'fields' => ['assignment_type'],
            'scope' => [

                'site_id' => $unrelatedSite->id,
                'site_ids' => [$unrelatedSite->id],
                'device_id' => $unrelatedDevice->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertSame($unrelatedActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertNull(data_get($audit->meta, 'scope'));
        $encoded = json_encode($audit->meta, JSON_THROW_ON_ERROR);
        foreach (['999999', '"site_id":'.$unrelatedSite->id, '"device_id":'.$unrelatedDevice->id] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }

        foreach ([$this->siteRestrictedViewer($canonicalSite), $this->siteRestrictedViewer($unrelatedSite)] as $viewer) {
            $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page): void {
                $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'));
            });
        }
    }

    public function test_site_assignment_uses_the_target_site_instead_of_caller_scope(): void
    {
        $device = Device::factory()->create([]);
        $targetSite = Site::factory()->create([]);
        $callerSite = Site::factory()->create([]);
        $callerClient = Client::factory()->create(['site_id' => $callerSite->id]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $targetSite->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'client_id' => $callerClient->id,
            'scope' => [
                'site_id' => $callerSite->id,
                'site_ids' => [$callerSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->client_id);
        $this->assertSame($targetSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$targetSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));

        $this->actingAs($this->siteRestrictedViewer($targetSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertTrue(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
        $this->actingAs($this->siteRestrictedViewer($callerSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertFalse(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
    }

    public function test_room_assignment_uses_the_rooms_site_instead_of_caller_scope(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $unrelatedSite = Site::factory()->create([]);
        $mismatchedRoom = SiteRoom::create([
            'site_id' => $canonicalSite->id,
            'name' => 'Mismatched room',
        ]);
        $device = Device::factory()->create([]);
        $unrelatedClient = Client::factory()->create(['site_id' => $unrelatedSite->id]);
        $unrelatedActor = User::factory()->create(['approved_at' => now()]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $mismatchedRoom->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'client_id' => $unrelatedClient->id,
            'actor_id' => $unrelatedActor->id,
            'scope' => [
                'site_id' => $unrelatedSite->id,
                'site_ids' => [$unrelatedSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertSame($unrelatedActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));

        $this->actingAs($this->siteRestrictedViewer($canonicalSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertTrue(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
        $this->actingAs($this->siteRestrictedViewer($unrelatedSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertFalse(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
    }

    public function test_client_assignment_uses_the_clients_site_instead_of_caller_scope(): void
    {
        $targetSite = Site::factory()->create([]);
        $callerSite = Site::factory()->create([]);
        $targetClient = Client::factory()->create(['site_id' => $targetSite->id]);
        $callerClient = Client::factory()->create(['site_id' => $callerSite->id]);
        $device = Device::factory()->create([]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $targetClient->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'client_id' => $callerClient->id,
            'scope' => [
                'site_id' => $callerSite->id,
                'site_ids' => [$callerSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->client_id);
        $this->assertSame($targetSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$targetSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));

        $this->actingAs($this->siteRestrictedViewer($targetSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertTrue(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
        $this->actingAs($this->siteRestrictedViewer($callerSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertFalse(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
    }

    public function test_all_sites_auditors_can_see_null_site_assignment_evidence(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $callerSite = Site::factory()->create([]);
        $siteLessClient = Client::factory()->create(['site_id' => null]);
        $callerClient = Client::factory()->create(['site_id' => $callerSite->id]);
        $vehicle = Asset::factory()->create([
            'category' => 'Vehicle',
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $siteLessClient->id,
        ]);
        $device = Device::factory()->create([]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'client_id' => $callerClient->id,
            'scope' => [
                'site_id' => $callerSite->id,
                'site_ids' => [$callerSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->client_id);
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertNull(data_get($audit->meta, 'scope.site_id'));
        $this->assertNull(data_get($audit->meta, 'scope.site_ids'));

        $secondAdmin = User::factory()->create(['approved_at' => now()]);
        $secondAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        foreach ([$this->admin, $secondAdmin] as $viewer) {
            $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page): void {
                $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'));
            });
        }

        $this->actingAs($this->siteRestrictedViewer($canonicalSite))
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $this->assertFalse(
                collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'),
            ));
    }

    public function test_vehicle_assignment_scope_preserves_only_consistent_site_evidence(): void
    {
        $site = Site::factory()->create([]);
        $siteLessClient = Client::factory()->create(['site_id' => null]);
        $siteClient = Client::factory()->create(['site_id' => $site->id]);
        $device = Device::factory()->create([]);
        $vehicles = [
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => null, 'home_site_id' => null, 'client_id' => $siteLessClient->id,
            ]),
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => null, 'home_site_id' => null, 'client_id' => $siteClient->id,
            ]),
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => $site->id, 'home_site_id' => null, 'client_id' => null,
            ]),
            Asset::factory()->create([
                'category' => 'Vehicle', 'site_id' => null, 'home_site_id' => $site->id, 'client_id' => null,
            ]),
        ];

        foreach ($vehicles as $index => $vehicle) {
            $scope = SafeOperationalData::auditScope(new DeviceAssignment([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
                'assignable_id' => $vehicle->id,
                'assigned_at' => now(),
            ]));

            $this->assertSame($device->id, $scope['device_id']);
            if ($index === 0) {
                $this->assertArrayNotHasKey('site_ids', $scope);
            } else {
                $this->assertSame([$site->id], $scope['site_ids']);
            }
        }
    }

    public function test_vehicle_audit_scope_never_reinterprets_a_residential_room_id_as_a_hardware_room(): void
    {
        $canonicalSite = Site::factory()->create([]);
        $collisionSite = Site::factory()->create([]);
        $collisionRoom = SiteRoom::query()->forceCreate([
            'id' => 900001,
            'site_id' => $collisionSite->id,
            'name' => 'Unrelated hardware room',
        ]);
        $residentialRoom = SiteHouseRoom::query()->forceCreate([
            'id' => $collisionRoom->id,
            'site_id' => $canonicalSite->id,
            'name' => 'Canonical residential room',
            'is_active' => true,
            'is_assignable' => false,
        ]);
        $vehicle = Asset::factory()->create([
            'category' => 'Vehicle',
            'site_id' => null,
            'home_site_id' => $canonicalSite->id,
            'client_id' => null,
            'room_id' => $residentialRoom->id,
        ]);
        $device = Device::factory()->create([]);

        $scope = SafeOperationalData::auditScope(new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $vehicle->id,
            'assigned_at' => now(),
        ]));

        $this->assertSame([$canonicalSite->id], $scope['site_ids']);
        $this->assertSame($canonicalSite->id, $scope['site_id']);
        $this->assertNotContains($collisionSite->id, $scope['site_ids']);
    }

    public function test_future_device_audit_uses_only_the_current_assignment_after_a_site_move(): void
    {
        $previousSite = Site::factory()->create([]);
        $currentSite = Site::factory()->create([]);
        $device = Device::factory()->create([]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $previousSite->id,
            'assigned_at' => now()->subDay(),
            'released_at' => now()->subHour(),
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $currentSite->id,
            'assigned_at' => now()->subHour(),
        ]);
        AuditLog::query()->delete();

        $device->update(['status' => 'offline']);

        $audit = AuditLog::query()->where('action', 'device.update')->sole();
        $this->assertSame([$currentSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($currentSite->id, data_get($audit->meta, 'scope.site_id'));

        $previousViewer = $this->siteRestrictedViewer($previousSite);
        $currentViewer = $this->siteRestrictedViewer($currentSite);

        $this->actingAs($previousViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($device): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$device->id));
        });
        $this->actingAs($currentViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($device): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$device->id));
        });
    }

    public function test_historical_assignment_audit_uses_its_persisted_site_scope_after_device_moves(): void
    {
        $previousSite = Site::factory()->create([]);
        $currentSite = Site::factory()->create([]);
        $device = Device::factory()->create([]);
        AuditLog::query()->delete();

        $previousAssignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $previousSite->id,
            'assigned_at' => now()->subDay(),
        ]);
        $previousAssignment->update(['released_at' => now()->subHour()]);
        $currentAssignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $currentSite->id,
            'assigned_at' => now()->subHour(),
        ]);

        $previousViewer = $this->siteRestrictedViewer($previousSite);
        $currentViewer = $this->siteRestrictedViewer($currentSite);

        $this->actingAs($previousViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($previousAssignment, $currentAssignment): void {
            $references = collect($page->toArray()['props']['audit']['entries'])
                ->where('record_type', 'DeviceAssignment')
                ->pluck('record_reference');
            $this->assertContains('#'.$previousAssignment->id, $references);
            $this->assertNotContains('#'.$currentAssignment->id, $references);
        });
        $this->actingAs($currentViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($previousAssignment, $currentAssignment): void {
            $references = collect($page->toArray()['props']['audit']['entries'])
                ->where('record_type', 'DeviceAssignment')
                ->pluck('record_reference');
            $this->assertNotContains('#'.$previousAssignment->id, $references);
            $this->assertContains('#'.$currentAssignment->id, $references);
        });
    }

    public function test_auditable_assignment_resolves_canonical_scope_once_at_persistence_boundary(): void
    {
        $site = Site::factory()->create([]);
        $device = Device::factory()->create([]);
        AuditLog::query()->delete();
        $scopeQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$scopeQueries): void {
            $sql = strtolower($query->sql);
            if (str_starts_with(ltrim($sql), 'select')
                && preg_match('/(?:from|join) [`"]?(devices|sites|site_rooms|clients|assets)[`"]?/', $sql) === 1) {
                $scopeQueries++;
            }
        });

        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $audit = AuditLog::query()->where('action', 'deviceassignment.create')->sole();
        $this->assertSame($site->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame($assignment->device_id, data_get($audit->meta, 'scope.device_id'));
        $this->assertLessThanOrEqual(6, $scopeQueries, 'Canonical assignment scope was resolved more than once.');
    }

    public function test_site_specific_integration_audit_is_limited_to_accessible_sites(): void
    {
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        AuditLog::query()->delete();
        $allowed = IntegrationSiteConfig::create([
            'site_id' => $allowedSite->id, 'provider' => 'unifi', 'is_active' => true,
        ]);
        $hidden = IntegrationSiteConfig::create([
            'site_id' => $hiddenSite->id, 'provider' => 'milesight', 'is_active' => true,
        ]);

        $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($allowed, $hidden): void {
            $references = collect($page->toArray()['props']['audit']['entries'])->pluck('record_reference');
            $this->assertContains('#'.$allowed->id, $references);
            $this->assertNotContains('#'.$hidden->id, $references);
        });
    }

    private function siteRestrictedViewer(Site $site): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $viewer;
    }

    public function test_deleted_site_audit_remains_visible_only_inside_the_persisted_scope(): void
    {
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id, 'secondary_site_ids' => [],
        ]);
        AuditLog::query()->delete();
        $allowed = IntegrationSiteConfig::create(['site_id' => $allowedSite->id, 'provider' => 'unifi']);
        $hidden = IntegrationSiteConfig::create(['site_id' => $hiddenSite->id, 'provider' => 'unifi']);
        $allowedId = $allowed->id;
        $hiddenId = $hidden->id;
        $allowed->delete();
        $hidden->delete();
        IntegrationSyncLog::create([
            'site_id' => null,
            'provider' => 'unifi',
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_FAILED,
            'items_errored' => 99,
            'started_at' => now(),
        ]);

        $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($allowedId, $hiddenId): void {
            $references = collect($page->toArray()['props']['audit']['entries'])
                ->where('action', 'integrationsiteconfig.delete')->pluck('record_reference');
            $this->assertContains('#'.$allowedId, $references);
            $this->assertNotContains('#'.$hiddenId, $references);
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('action', 'integrationsynclog.create'));
        });

        $this->actingAs($this->admin)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($allowedId, $hiddenId): void {
            $entries = collect($page->toArray()['props']['audit']['entries']);
            $deletedReferences = $entries->where('action', 'integrationsiteconfig.delete')->pluck('record_reference');
            $this->assertContains('#'.$allowedId, $deletedReferences);
            $this->assertContains('#'.$hiddenId, $deletedReferences);
            $this->assertTrue($entries->contains('action', 'integrationsynclog.create'));
        });
    }

    public function test_deleted_devices_and_assignments_persist_safe_site_ids_for_all_canonical_assignment_contexts(): void
    {
        $site = Site::factory()->create([]);
        $room = SiteRoom::create(['site_id' => $site->id, 'name' => 'Safe room']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        ]);
        $vehicle = Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id]);

        AuditLog::query()->delete();
        $deletedDeviceIds = [];
        $deletedAssignmentIds = [];
        $deletedAssignmentDeviceIds = [];
        foreach ([
            [DeviceAssignment::TARGET_ROOM, $room->id],
            [DeviceAssignment::TARGET_CLIENT, $client->id],
            [DeviceAssignment::TARGET_STAFF, $staff->id],
            [DeviceAssignment::TARGET_VEHICLE, $vehicle->id],
        ] as [$type, $targetId]) {
            $device = Device::factory()->create([]);
            $assignment = DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $type,
                'assignable_id' => $targetId,
                'assigned_at' => now(),
            ]);
            $device->delete();
            $deletedDeviceIds[] = $device->id;

            $assignmentDevice = Device::factory()->create([]);
            $deletedAssignment = DeviceAssignment::create([
                'device_id' => $assignmentDevice->id,
                'assignable_type' => $type,
                'assignable_id' => $targetId,
                'assigned_at' => now(),
            ]);
            $deletedAssignmentIds[] = $deletedAssignment->id;
            $deletedAssignmentDeviceIds[$deletedAssignment->id] = $assignmentDevice->id;
            $assignmentDevice->delete();
            $deletedDeviceIds[] = $assignmentDevice->id;
            $deletedAssignment->delete();
        }

        $deletions = AuditLog::query()->whereIn('action', ['device.delete', 'deviceassignment.delete'])->get();
        $this->assertCount(12, $deletions);
        foreach ($deletions as $deletion) {
            $this->assertSame([$site->id], data_get($deletion->meta, 'scope.site_ids'));
            if ($deletion->action === 'deviceassignment.delete') {
                $this->assertSame($deletedAssignmentDeviceIds[$deletion->auditable_id], data_get($deletion->meta, 'scope.device_id'));
            }
            $encoded = json_encode($deletion->meta, JSON_THROW_ON_ERROR);
            foreach ([$client->id, $staff->id, $vehicle->id] as $privateId) {
                $this->assertStringNotContainsString('"assignable_id":'.$privateId, $encoded);
            }
        }

        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        ]);

        $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($deletedDeviceIds, $deletedAssignmentIds): void {
            $entries = collect($page->toArray()['props']['audit']['entries']);
            $deletions = $entries->whereIn('action', ['device.delete', 'deviceassignment.delete']);
            $this->assertCount(12, $deletions);
            $this->assertEqualsCanonicalizing(
                collect($deletedDeviceIds)->map(fn (int $id): string => '#'.$id)->all(),
                $deletions->where('record_type', 'Device')->pluck('record_reference')->all(),
            );
            $this->assertEqualsCanonicalizing(
                collect($deletedAssignmentIds)->map(fn (int $id): string => '#'.$id)->all(),
                $deletions->where('record_type', 'DeviceAssignment')->pluck('record_reference')->all(),
            );
        });
    }

    public function test_site_restricted_group_count_only_includes_groups_with_visible_devices(): void
    {
        $allowedSite = Site::factory()->create([]);
        $hiddenSite = Site::factory()->create([]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id, 'secondary_site_ids' => [],
        ]);
        $allowedDevice = Device::factory()->create([]);
        $hiddenDevice = Device::factory()->create([]);
        foreach ([[$allowedDevice, $allowedSite], [$hiddenDevice, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::create([
                'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id, 'assigned_at' => now(),
            ]);
            $group = DeviceGroup::create(['name' => 'Group '.$site->id, 'type' => 'manual']);
            $group->devices()->attach($device->id);
        }

        $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.device_groups', 1));
    }
}
