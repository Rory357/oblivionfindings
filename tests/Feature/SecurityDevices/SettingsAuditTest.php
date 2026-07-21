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
        $this->admin = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_user_without_groups_or_reports_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['organization_id' => 42]);
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $this->actingAs($user)->get('/security-devices/settings')->assertForbidden();
    }

    public function test_settings_projects_real_safe_defaults_profiles_exceptions_and_feature_support(): void
    {
        IntegrationProviderConnection::create([
            'tenant_id' => 42,
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
            'tenant_id' => 42,
            'name' => 'Critical infrastructure',
            'description' => 'Fast checks for core paths',
            'interval_seconds' => 60,
            'failure_confirmations' => 3,
            'recovery_confirmations' => 2,
            'stale_after_seconds' => 300,
            'is_active' => true,
        ]);
        MonitoringProfile::factory()->create(['tenant_id' => 77, 'name' => 'Foreign profile']);
        Device::factory()->create(['tenant_id' => 42, 'provider' => 'unifi']);
        Device::factory()->create(['tenant_id' => 77, 'provider' => 'unifi']);

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
                $this->assertSame(['Critical infrastructure', 'Foreign profile'], collect($props['monitoringProfiles'])->pluck('name')->all());
                $this->assertSame(2, $props['dataQuality']['unassigned_devices']);
                $this->assertSame('unsupported', $props['featureSupport']['discovery_candidates']['state']);
                $this->assertSame('read_only_append_only_application_evidence', $props['audit']['evidence_state']);
                $encoded = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('RAW-', $encoded);
                $this->assertStringNotContainsString('secret_encrypted', $encoded);
            });
    }

    public function test_audit_is_report_permission_only_whitelisted_record_scoped_and_safely_projected(): void
    {
        $device = Device::factory()->create(['tenant_id' => 42]);
        $client = Client::factory()->create(['organization_id' => 42]);
        $foreign = Device::factory()->create(['tenant_id' => 77]);
        AuditLog::query()->delete();

        AuditLog::create([
            'organization_id' => 42,
            'user_id' => $this->admin->id,
            'action' => 'device.update',
            'auditable_type' => Device::class,
            'auditable_id' => $device->id,
            'meta' => ['fields' => ['name', 'secret_encrypted', 'external_ref'], 'before' => ['name' => 'RAW-BEFORE'], 'after' => ['name' => 'RAW-AFTER']],
            'ip_address' => '10.1.2.3',
            'user_agent' => 'RAW-AGENT',
        ]);
        AuditLog::create([
            'organization_id' => 42,
            'action' => 'client.update',
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'meta' => ['fields' => ['name']],
        ]);
        AuditLog::create([
            'organization_id' => 77,
            'action' => 'device.update',
            'auditable_type' => Device::class,
            'auditable_id' => $foreign->id,
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
            'tenant_id' => 42,
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
        $site = Site::factory()->create(['tenant_id' => 42]);
        $device = Device::factory()->create([
            'tenant_id' => 42,
            'external_ref' => ['provider_entity_id' => 'RAW-DEVICE-REF'],
            'config' => ['token' => 'RAW-DEVICE-CONFIG'],
            'meta' => ['payload' => 'RAW-DEVICE-META'],
        ]);
        $device->update(['status' => 'offline', 'config' => ['token' => 'RAW-UPDATED-CONFIG']]);
        Integration::create([
            'tenant_id' => 42, 'provider' => 'unifi', 'display_name' => 'UniFi',
            'status' => Integration::STATUS_ERROR, 'config' => ['url' => 'https://RAW-INTEGRATION.test'],
            'last_error' => 'Bearer RAW-INTEGRATION-ERROR',
        ]);
        IntegrationSiteConfig::create([
            'tenant_id' => 42, 'site_id' => $site->id, 'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'RAW-EXTERNAL-ID',
            'overrides' => ['credential' => 'RAW-OVERRIDE'], 'is_active' => true,
        ]);
        IntegrationSyncLog::create([
            'tenant_id' => 42, 'site_id' => $site->id, 'provider' => 'unifi',
            'action' => 'sync_devices', 'status' => IntegrationSyncLog::STATUS_FAILED,
            'error_message' => 'https://RAW-SYNC.test/?token=secret', 'started_at' => now(),
        ]);
        IntegrationEvent::create([
            'tenant_id' => 42, 'site_id' => $site->id, 'canonical_device_id' => $device->id,
            'provider' => 'unifi', 'source_app' => 'protect', 'source_event_id' => 'RAW-EVENT-ID',
            'occurred_at' => now(), 'received_at' => now(), 'severity' => 'warn', 'event_type' => 'motion',
            'normalized_payload' => ['token' => 'RAW-NORMALIZED'], 'raw_payload' => ['frame' => 'RAW-PAYLOAD'],
        ]);

        $encoded = AuditLog::query()->where('organization_id', 42)->pluck('meta')->toJson();
        foreach (['RAW-', 'external_ref', 'config', 'meta', 'mapped_external_site_id', 'overrides', 'error_message', 'normalized_payload', 'raw_payload'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }
        $this->assertStringContainsString('status', $encoded);
        $this->assertStringContainsString('offline', $encoded);
        $this->assertStringContainsString('scope', $encoded);
    }

    public function test_direct_audit_logger_calls_are_sanitized_for_protected_models(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42]);
        $client = Client::factory()->create(['organization_id' => 42, 'site_id' => $site->id]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        AuditLogger::logOrFail('device.update', $device, [
            'organization_id' => 42,
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
                'tenant_id' => 42,
                'device_id' => $device->id,
                'site_ids' => [$site->id],
                'remote_target_id' => 'RAW-TARGET-ID',
            ],
            'secret' => 'RAW-TOP-LEVEL-SECRET',
            'arbitrary' => ['command' => 'RAW-COMMAND'],
        ]);

        $audit = AuditLog::query()->where('action', 'device.update')->latest('id')->firstOrFail();
        $this->assertSame(42, $audit->organization_id);
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

    public function test_protected_audit_logger_derives_canonical_organization_and_scope_instead_of_trusting_callers(): void
    {
        $canonicalSite = Site::factory()->create(['tenant_id' => 42]);
        $unrelatedSite = Site::factory()->create(['tenant_id' => 42]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        $foreignDevice = Device::factory()->create(['tenant_id' => 77]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $canonicalSite->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('device.update', $device, [
            'organization_id' => 77,
            'fields' => ['status'],
            'after' => ['status' => 'offline'],
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $unrelatedSite->id,
                'device_id' => $foreignDevice->id,
                'site_ids' => [$unrelatedSite->id],
                'device_ids' => [$foreignDevice->id],
            ],
        ]);

        $audit = AuditLog::query()->where('action', 'device.update')->sole();
        $this->assertSame(42, $audit->organization_id);
        $this->assertSame(42, data_get($audit->meta, 'scope.tenant_id'));
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertNull(data_get($audit->meta, 'scope.device_ids'));
        $this->assertStringNotContainsString((string) $foreignDevice->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $rightfulViewer = $this->siteRestrictedViewer($canonicalSite);
        $unrelatedViewer = $this->siteRestrictedViewer($unrelatedSite);

        $this->actingAs($rightfulViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($device): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$device->id));
        });
        $this->actingAs($unrelatedViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($device): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$device->id));
        });
    }

    public function test_device_asset_link_audit_cannot_be_rehomed_by_foreign_caller_context(): void
    {
        $canonicalSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        $foreignDevice = Device::factory()->create(['tenant_id' => 77]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $canonicalSite->id,
            'assigned_at' => now(),
        ]);
        $foreignClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $foreignActor = User::factory()->create(['organization_id' => 77, 'approved_at' => now()]);
        $link = DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => Asset::factory()->create()->id,
            'link_type' => 'primary',
            'linked_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassetlink.update', $link, [
            'organization_id' => 77,
            'client_id' => $foreignClient->id,
            'actor_id' => $foreignActor->id,
            'fields' => ['link_type'],
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $foreignDevice->id,
            ],
        ]);

        $audit = AuditLog::query()->where('action', 'deviceassetlink.update')->sole();
        $this->assertSame(42, $audit->organization_id);
        $this->assertSame($foreignActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertSame(42, data_get($audit->meta, 'scope.tenant_id'));
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertStringNotContainsString('"tenant_id":77', json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"site_id":'.$foreignSite->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"device_id":'.$foreignDevice->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $rightfulViewer = $this->siteRestrictedViewer($canonicalSite);
        $hiddenViewer = $this->siteRestrictedViewer($hiddenSite);
        $foreignViewer = $this->siteRestrictedViewer($foreignSite);

        $this->actingAs($rightfulViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($link): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$link->id));
        });
        $this->actingAs($hiddenViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($link): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$link->id));
        });
        $this->actingAs($foreignViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($link): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$link->id));
        });
    }

    public function test_device_maintenance_audit_cannot_be_rehomed_and_has_no_actor_fallback_without_a_canonical_parent(): void
    {
        $canonicalSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        $foreignDevice = Device::factory()->create(['tenant_id' => 77]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $canonicalSite->id,
            'assigned_at' => now(),
        ]);
        $foreignClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $foreignActor = User::factory()->create(['organization_id' => 77, 'approved_at' => now()]);
        $record = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'inspection',
            'status' => 'scheduled',
            'description' => 'Canonical inspection',
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('devicemaintenancerecord.update', $record, [
            'organization_id' => 77,
            'client_id' => $foreignClient->id,
            'actor_id' => $foreignActor->id,
            'fields' => ['status'],
            'after' => ['status' => 'completed'],
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $foreignDevice->id,
            ],
        ]);

        $audit = AuditLog::query()->where('action', 'devicemaintenancerecord.update')->sole();
        $this->assertSame(42, $audit->organization_id);
        $this->assertSame($foreignActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertSame(42, data_get($audit->meta, 'scope.tenant_id'));
        $this->assertSame($canonicalSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$canonicalSite->id], data_get($audit->meta, 'scope.site_ids'));
        $this->assertSame($device->id, data_get($audit->meta, 'scope.device_id'));
        $this->assertStringNotContainsString('"tenant_id":77', json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"site_id":'.$foreignSite->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('"device_id":'.$foreignDevice->id, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $rightfulViewer = $this->siteRestrictedViewer($canonicalSite);
        $foreignViewer = $this->siteRestrictedViewer($foreignSite);
        $this->actingAs($rightfulViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($record): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$record->id));
        });
        $this->actingAs($foreignViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($record): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('record_reference', '#'.$record->id));
        });

        $orphan = new DeviceMaintenanceRecord(['device_id' => 999999]);
        AuditLogger::logOrFail('devicemaintenancerecord.update', $orphan, [
            'organization_id' => 77,
            'client_id' => $foreignClient->id,
            'actor_id' => $foreignActor->id,
            'scope' => ['tenant_id' => 77, 'device_id' => $foreignDevice->id],
        ]);
        $orphanAudit = AuditLog::query()->whereNull('auditable_id')->where('action', 'devicemaintenancerecord.update')->sole();
        $this->assertNull($orphanAudit->organization_id);
        $this->assertNull($orphanAudit->client_id);
        $this->assertNull(data_get($orphanAudit->meta, 'scope'));
    }

    public function test_protected_child_model_survey_uses_only_unambiguous_canonical_device_relations(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42]);
        $firstDevice = Device::factory()->create(['tenant_id' => 42]);
        $secondDevice = Device::factory()->create(['tenant_id' => 42]);
        $foreignDevice = Device::factory()->create(['tenant_id' => 77]);
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
            $this->assertSame(42, data_get($scope, 'tenant_id'));
            $this->assertSame($firstDevice->id, data_get($scope, 'device_id'));
            $this->assertSame([$site->id], data_get($scope, 'site_ids'));
        }

        $group = DeviceGroup::create(['tenant_id' => 42, 'name' => 'Canonical group', 'type' => 'manual']);
        $foreignGroup = DeviceGroup::create(['tenant_id' => 77, 'name' => 'Foreign group', 'type' => 'manual']);
        $memberScope = SafeOperationalData::auditScope(new DeviceGroupMember([
            'device_group_id' => $group->id,
            'device_id' => $firstDevice->id,
        ]));
        $this->assertSame(42, data_get($memberScope, 'tenant_id'));
        $this->assertSame($firstDevice->id, data_get($memberScope, 'device_id'));
        $this->assertSame([], SafeOperationalData::auditScope(new DeviceGroupMember([
            'device_group_id' => $foreignGroup->id,
            'device_id' => $firstDevice->id,
        ])));

        $relationshipScope = SafeOperationalData::auditScope(new DeviceRelationship([
            'parent_device_id' => $firstDevice->id,
            'child_device_id' => $secondDevice->id,
            'relationship_type' => 'connected_to',
        ]));
        $this->assertSame(42, data_get($relationshipScope, 'tenant_id'));
        $this->assertEqualsCanonicalizing([$firstDevice->id, $secondDevice->id], data_get($relationshipScope, 'device_ids'));
        $this->assertSame([$site->id], data_get($relationshipScope, 'site_ids'));
        $this->assertSame([], SafeOperationalData::auditScope(new DeviceRelationship([
            'parent_device_id' => $firstDevice->id,
            'child_device_id' => $foreignDevice->id,
            'relationship_type' => 'connected_to',
        ])));
    }

    public function test_orphan_device_assignment_audit_discards_fabricated_device_and_caller_scope(): void
    {
        $canonicalSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $foreignDevice = Device::factory()->create(['tenant_id' => 77]);
        $foreignClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $foreignActor = User::factory()->create(['organization_id' => 77, 'approved_at' => now()]);
        $orphan = new DeviceAssignment([
            'device_id' => 999999,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $foreignSite->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $orphan, [
            'organization_id' => 77,
            'client_id' => $foreignClient->id,
            'actor_id' => $foreignActor->id,
            'fields' => ['assignment_type'],
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $foreignDevice->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->organization_id);
        $this->assertSame($foreignActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertNull(data_get($audit->meta, 'scope'));
        $encoded = json_encode($audit->meta, JSON_THROW_ON_ERROR);
        foreach (['999999', '"tenant_id":77', '"site_id":'.$foreignSite->id, '"device_id":'.$foreignDevice->id] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }

        foreach ([$this->siteRestrictedViewer($canonicalSite), $this->siteRestrictedViewer($foreignSite)] as $viewer) {
            $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page): void {
                $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'));
            });
        }
    }

    public function test_device_assignment_with_cross_tenant_target_discards_all_scope(): void
    {
        $device = Device::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $foreignClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $foreignSite->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'organization_id' => 77,
            'client_id' => $foreignClient->id,
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->client_id);
        $this->assertNull(data_get($audit->meta, 'scope'));
    }

    public function test_device_assignment_with_mismatched_room_and_site_tenants_discards_all_scope(): void
    {
        $canonicalSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $mismatchedRoom = SiteRoom::create([
            'tenant_id' => 77,
            'site_id' => $canonicalSite->id,
            'name' => 'Mismatched room',
        ]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        $foreignClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $foreignActor = User::factory()->create(['organization_id' => 77, 'approved_at' => now()]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_ROOM,
            'assignable_id' => $mismatchedRoom->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'organization_id' => 77,
            'client_id' => $foreignClient->id,
            'actor_id' => $foreignActor->id,
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->organization_id);
        $this->assertSame($foreignActor->id, $audit->user_id);
        $this->assertNull($audit->client_id);
        $this->assertNull(data_get($audit->meta, 'scope'));

        foreach ([$this->siteRestrictedViewer($canonicalSite), $this->siteRestrictedViewer($foreignSite)] as $viewer) {
            $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page): void {
                $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])->contains('action', 'deviceassignment.update'));
            });
        }
    }

    public function test_device_assignment_with_mismatched_client_and_site_tenants_discards_all_scope(): void
    {
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $mismatchedClient = Client::factory()->create(['organization_id' => 42, 'site_id' => $foreignSite->id]);
        $foreignCallerClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $mismatchedClient->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'organization_id' => 77,
            'client_id' => $foreignCallerClient->id,
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->client_id);
        $this->assertNull(data_get($audit->meta, 'scope'));
    }

    public function test_all_sites_auditors_can_see_unscoped_legacy_assignment_evidence(): void
    {
        $canonicalSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $mismatchedClient = Client::factory()->create(['organization_id' => 42, 'site_id' => $foreignSite->id]);
        $foreignCallerClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);
        $vehicle = Asset::factory()->create([
            'category' => 'Vehicle',
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $mismatchedClient->id,
        ]);
        $device = Device::factory()->create(['tenant_id' => 42]);
        $assignment = new DeviceAssignment([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);
        AuditLog::query()->delete();

        AuditLogger::logOrFail('deviceassignment.update', $assignment, [
            'organization_id' => 77,
            'client_id' => $foreignCallerClient->id,
            'scope' => [
                'tenant_id' => 77,
                'site_id' => $foreignSite->id,
                'site_ids' => [$foreignSite->id],
                'device_id' => $device->id,
            ],
        ]);

        $audit = AuditLog::query()->whereNull('auditable_id')->where('action', 'deviceassignment.update')->sole();
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->client_id);
        $this->assertNull(data_get($audit->meta, 'scope'));

        $foreignAdmin = User::factory()->create(['organization_id' => 77, 'approved_at' => now()]);
        $foreignAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        foreach ([$this->admin, $foreignAdmin] as $viewer) {
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

    public function test_vehicle_assignment_scope_preserves_only_consistent_tenant_evidence(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42]);
        $siteLessClient = Client::factory()->create(['organization_id' => 42, 'site_id' => null]);
        $siteClient = Client::factory()->create(['organization_id' => 42, 'site_id' => $site->id]);
        $device = Device::factory()->create(['tenant_id' => 42]);
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

            $this->assertSame(42, $scope['tenant_id']);
            $this->assertSame($device->id, $scope['device_id']);
            if ($index === 0) {
                $this->assertArrayNotHasKey('site_ids', $scope);
            } else {
                $this->assertSame([$site->id], $scope['site_ids']);
            }
        }
    }

    public function test_future_device_audit_uses_only_the_current_assignment_after_a_site_move(): void
    {
        $previousSite = Site::factory()->create(['tenant_id' => 42]);
        $currentSite = Site::factory()->create(['tenant_id' => 42]);
        $device = Device::factory()->create(['tenant_id' => 42]);
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
        $previousSite = Site::factory()->create(['tenant_id' => 42]);
        $currentSite = Site::factory()->create(['tenant_id' => 42]);
        $device = Device::factory()->create(['tenant_id' => 42]);
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
        $site = Site::factory()->create(['tenant_id' => 42]);
        $device = Device::factory()->create(['tenant_id' => 42]);
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
        $allowedSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 42]);
        $viewer = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42,
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);
        AuditLog::query()->delete();
        $allowed = IntegrationSiteConfig::create([
            'tenant_id' => 42, 'site_id' => $allowedSite->id, 'provider' => 'unifi', 'is_active' => true,
        ]);
        $hidden = IntegrationSiteConfig::create([
            'tenant_id' => 42, 'site_id' => $hiddenSite->id, 'provider' => 'milesight', 'is_active' => true,
        ]);

        $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($allowed, $hidden): void {
            $references = collect($page->toArray()['props']['audit']['entries'])->pluck('record_reference');
            $this->assertContains('#'.$allowed->id, $references);
            $this->assertNotContains('#'.$hidden->id, $references);
        });
    }

    private function siteRestrictedViewer(Site $site): User
    {
        $viewer = User::factory()->create(['organization_id' => (int) $site->tenant_id, 'approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => (int) $site->tenant_id,
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $viewer;
    }

    public function test_deleted_site_audit_remains_visible_only_inside_the_persisted_scope(): void
    {
        $allowedSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 42]);
        $viewer = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42, 'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id, 'secondary_site_ids' => [],
        ]);
        AuditLog::query()->delete();
        $allowed = IntegrationSiteConfig::create(['tenant_id' => 42, 'site_id' => $allowedSite->id, 'provider' => 'unifi']);
        $hidden = IntegrationSiteConfig::create(['tenant_id' => 42, 'site_id' => $hiddenSite->id, 'provider' => 'unifi']);
        $allowedId = $allowed->id;
        $hiddenId = $hidden->id;
        $allowed->delete();
        $hidden->delete();
        IntegrationSyncLog::create([
            'tenant_id' => 42,
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
        $site = Site::factory()->create(['tenant_id' => 42]);
        $room = SiteRoom::create(['tenant_id' => 42, 'site_id' => $site->id, 'name' => 'Safe room']);
        $client = Client::factory()->create(['organization_id' => 42, 'site_id' => $site->id]);
        $staff = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42, 'user_id' => $staff->id,
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
            $device = Device::factory()->create(['tenant_id' => 42]);
            $assignment = DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $type,
                'assignable_id' => $targetId,
                'assigned_at' => now(),
            ]);
            $device->delete();
            $deletedDeviceIds[] = $device->id;

            $assignmentDevice = Device::factory()->create(['tenant_id' => 42]);
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

        $viewer = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42, 'user_id' => $viewer->id,
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
        $allowedSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 42]);
        $viewer = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42, 'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id, 'secondary_site_ids' => [],
        ]);
        $allowedDevice = Device::factory()->create(['tenant_id' => 42]);
        $hiddenDevice = Device::factory()->create(['tenant_id' => 42]);
        foreach ([[$allowedDevice, $allowedSite], [$hiddenDevice, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::create([
                'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id, 'assigned_at' => now(),
            ]);
            $group = DeviceGroup::create(['tenant_id' => 42, 'name' => 'Group '.$site->id, 'type' => 'manual']);
            $group->devices()->attach($device->id);
        }

        $this->actingAs($viewer)->get('/security-devices/settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.device_groups', 1));
    }
}
